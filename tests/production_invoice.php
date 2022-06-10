<?php

if( !defined('STDIN') || !(empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0))
{
    die("This should run in CLI");
}

require __DIR__ . '/../vendor/autoload.php';

use ox\Logger;
use ox\XeroWrapper;
use ox\OrderhiveWrapper;

Logger::getInstance()->setModes(['stdout']);

$configuration = json_decode(file_get_contents(__DIR__.'/../configuration.json'));

$orderhiveAPIConfig = [];
foreach($configuration->credentials->orderhive as $key => $value)
{
    $orderhiveAPIConfig[$key] = $value;
}
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

var_dump($x->updateBundleLineItems(0, $orderhiveProducts, '02081b4f-8f0e-4f58-b68f-e9b731e01bab'));