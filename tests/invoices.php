<?php

if( !defined('STDIN') || !(empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0))
{
    die("This should run in CLI");
}

require __DIR__ . '/../vendor/autoload.php';

use ox\Logger;
use ox\XeroWrapper;

$logFile = __DIR__.'/../logs/orderhive_xero-invoices_test-'.date('Y-m-d_His').'.log';
Logger::getInstance()->setOutputFile($logFile);

$configuration = json_decode(file_get_contents(__DIR__.'/../configuration.json'));
$clientId = $configuration->credentials->xero->client_id;
$clientSecret = $configuration->credentials->xero->client_secret;
$redirectUri = $configuration->credentials->xero->redirect_uri;

$x = new XeroWrapper($clientId, $clientSecret, $redirectUri);

$orderhiveProducts = [
    'BUNDLE1'=>[
        'bundle_of' =>[
            'ITEM_OF_BUNDLE_3'=>[
                'cost' => 5,
                'description' => 'Item of bundle 1',
                'componentQuantity' => 1,
                'sku'=>'ITEM_OF_BUNDLE_3'
            ],
            'ITEM_OF_BUNDLE_4'=>[
                'cost' => 15,
                'description' => 'Item of bundle 2',
                'componentQuantity' => 3,
                'sku'=>'ITEM_OF_BUNDLE_4'
            ]
        ]
    ]
];

var_dump($x->updateBundleLineItems(0, $orderhiveProducts, 'eb81fe79-8dd4-4151-9d51-d2ad8aa3e893'));