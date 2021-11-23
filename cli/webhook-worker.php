
<?php

if( !defined('STDIN') || !(empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0))
{
    die("This should run in CLI");
}

$configurationFile = __DIR__.'/../configuration.json';
if (!file_exists($configurationFile)) {
    echo "Error: $configurationFile not found";
    exit(1);
}
$configuration = json_decode(file_get_contents($configurationFile));

$jsonPayloadFiles = [];
$iterator = new FilesystemIterator(__DIR__.'/../cache', FilesystemIterator::CURRENT_AS_PATHNAME);
foreach ($iterator as $fileinfo)
{
    $filePath = $iterator->current();
    if (preg_match('/^.+(webhook\-payload).+(\.json)$/',$filePath) !== 0)
    {
        $jsonPayloadFiles[] = $filePath;
    }
}

if (empty($jsonPayloadFiles)) {
    echo "No payloads to process";
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

use ox\XeroWrapper;
use ox\OrderhiveWrapper;
use ox\Logger;

//https://www.php.net/manual/pt_BR/function.set-time-limit.php
//application execution limit: 1 hour
set_time_limit(60*60);

//https://haydenjames.io/understanding-php-memory_limit
//maximum memory to be used
ini_set('memory_limit', '256M');

//show errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//cache time to reuse expensive API queries
$cacheInMinutes = isset($configuration->cacheInMinutes) ? $configuration->cacheInMinutes : 10;

//only log to file
$logFile = __DIR__.'/../logs/orderhive_xero-webhook_worker-'.date('Y-m-d_His').'.log';
Logger::getInstance()->setModes(['file']);
Logger::getInstance()->setOutputFile($logFile);

//https://www.php.net/manual/pt_BR/function.php-ini-loaded-file.php
Logger::getInstance()->log("php.ini location: '".php_ini_loaded_file()."'");

//see https://www.php.net/manual/en/timezones.php
if (isset($configuration->phpTimezone) && !empty($configuration->phpTimezone))
{
    Logger::getInstance()->log("Setting PHP timezone '" . $configuration->phpTimezone . "'");
    date_default_timezone_set($configuration->phpTimezone);
}
Logger::getInstance()->log("Current PHP timezone: '" . date_default_timezone_get() . "'");

$orderhiveAPIConfig = [
    'id_token' => $configuration->credentials->orderhive->id_token,
    'refresh_token' => $configuration->credentials->orderhive->refresh_token
];
$app = new \OrderHive\OrderHive($orderhiveAPIConfig);
$orderhiveWrapper = new OrderhiveWrapper(
    $app,
    $configuration->retries,
    $configuration->timeBetweenRetries);
$orderhiveProducts = $orderhiveWrapper->getProducts($configuration->cacheInMinutes)['result'];

$xeroWrapper = new XeroWrapper(
    $configuration->credentials->xero->client_id,
    $configuration->credentials->xero->client_secret,
    $configuration->credentials->xero->redirect_uri,
    $configuration->credentials->xero->webhook_key
);

foreach ($jsonPayloadFiles as $jsonPayloadFile)
{
    $jsonPayload = json_decode(file_get_contents($jsonPayloadFile));
    foreach($jsonPayload->events as $event)
    {
        if ($event->tenantId === $configuration->credentials->xero->tenant_id &&
            $event->eventCategory == 'INVOICE' &&
            in_array($event->eventType, ['UPDATE','CREATE']))
        {
            $invoiceId = $event->resourceId;
            Logger::getInstance()->log("Trying to update invoice $invoiceId");
            if ($xeroWrapper->updateBundleLineItems(
                $configuration->cacheInMinutes, $orderhiveProducts, $invoiceId))
            {
                Logger::getInstance()->log("$jsonPayloadFile processed, deleting");
                unlink($jsonPayloadFile);
            }
            else {
                Logger::getInstance()->log("$jsonPayloadFile unprocessed, keeping for audit purposes");
                rename($jsonPayloadFile, $jsonPayloadFile . '.unprocessed');
            }
        }
        else
        {
            Logger::getInstance()->log("$jsonPayloadFile has an invalid request payload, deleting");
            unlink($jsonPayloadFile);
        }
    }
}

exit(0);