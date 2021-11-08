<?php

// Compute the payload with HMACSHA256 with base64 encoding
$computedSignatureKey = base64_encode(
    hash_hmac('sha256', $rawPayload, $webhookKey, true)
);
  
// Signature key from Xero request
$xeroSignatureKey = $_SERVER['HTTP_X_XERO_SIGNATURE'];

// Response HTTP status code when:
//   200: Correctly signed payload
//   401: Incorrectly signed payload
if (!hash_equals($computedSignatureKey, $xeroSignatureKey))
{
    http_response_code(401);
    die();
}

require __DIR__ . '/vendor/autoload.php';

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
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$rawPayload = file_get_contents('php://input');

$configurationFile = __DIR__.'/configuration.json';
if (!file_exists($configurationFile)) {
    die($configurationFile . ' not found');
}
$configuration = json_decode(file_get_contents($configurationFile));

//cache time to reuse expensive API queries
$cacheInMinutes = isset($configuration->cacheInMinutes) ? $configuration->cacheInMinutes : 10;

//log to file
$logFile = isset($configuration->logFile) && !empty($configuration->logFile) ?
	$configuration->logFile : 
	'./orderhive_dimass-sync-'.date('Y-m-d_His').'.log';
Logger::getInstance()->setModes(['file']);
Logger::getInstance()->setOutputFile($logFile);

//https://www.php.net/manual/pt_BR/function.php-ini-loaded-file.php
Logger::getInstance()->log("php.ini location: '".php_ini_loaded_file()."'");
Logger::getInstance()->log("Running in '$configuration->mode' mode");

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
$orderhiveProducts = $orderhiveWrapper->getProducts($cacheInMinutes)['result'];

$xeroWrapper = new XeroWrapper(
    $configuration->credentials->xero->client_id,
    $configuration->credentials->xero->client_secret,
    $configuration->credentials->xero->redirect_uri,
    $configuration->credentials->xero->webhook_key
);

$payloadJson = json_decode($rawPayload);

if (isset($payloadJson->events) && !empty($payloadJson->events))
{
    foreach($payloadJson->events as $event)
    {
        if ($event->tenantId == $configuration->credentials->xero->tenant_id &&
            $event->eventCategory == 'INVOICE' &&
            in_array($event->eventType, ['UPDATED','CREATED']))
        {
            $invoiceId = $event->resourceId;
            $xeroWrapper->updateBundleLineItems($orderhiveProducts, $invoiceId);
        }
    }
}

http_response_code(200);