<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if( !defined('STDIN') || !(empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0))
{
    die("This should run in CLI");
}

require __DIR__ . '/../vendor/autoload.php';

use ox\Logger;
use ox\XeroWrapper;

$logFile = __DIR__.'/../logs/all_items_test-'.date('Y-m-d_His').'.log';
Logger::getInstance()->setOutputFile($logFile);
//Logger::getInstance()->log('init');

$configuration = json_decode(file_get_contents(__DIR__.'/../configuration.json'));

//var_dump($configuration);

$clientId = $configuration->credentials->xero->client_id;
$clientSecret = $configuration->credentials->xero->client_secret;
$redirectUri = $configuration->credentials->xero->redirect_uri;

$x = new XeroWrapper($clientId, $clientSecret, $redirectUri);

var_dump($x->getAllItems(0));