<?php

if( !defined('STDIN') || !(empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0))
{
    die("This should run in CLI");
}

require __DIR__ . '/../vendor/autoload.php';

use ox\Logger;
use ox\XeroWrapper;
use ox\OrderhiveWrapper;

$logFile = __DIR__.'/../logs/test-'.date('Y-m-d_His').'.log';
Logger::getInstance()->setOutputFile($logFile);

$configuration = json_decode(file_get_contents(__DIR__.'/../configuration.json'));

$orderhiveAPIConfig = [
    'id_token' => $configuration->credentials->orderhive->id_token,
    'refresh_token' => $configuration->credentials->orderhive->refresh_token
];
$app = new \OrderHive\OrderHive($orderhiveAPIConfig);
$orderhiveWrapper = new OrderhiveWrapper(
    $app,
    $configuration->retries,
    $configuration->timeBetweenRetries);
$orderhiveProducts = $orderhiveWrapper->getProducts(10)['result'];

$clientId = $configuration->credentials->xero->client_id;
$clientSecret = $configuration->credentials->xero->client_secret;
$redirectUri = $configuration->credentials->xero->redirect_uri;

$x = new XeroWrapper($clientId, $clientSecret, $redirectUri);

var_dump($x->updateBundleLineItems(0, $orderhiveProducts, '81d3798c-90ff-456c-af0a-1bb562288b71'));