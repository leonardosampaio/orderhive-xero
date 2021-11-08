<?php

require __DIR__ . '/vendor/autoload.php';

use ox\Logger;
use ox\XeroWrapper;

$logFile = __DIR__.'/logs/orderhive_xero-cli-'.date('Y-m-d_His').'.log';
Logger::getInstance()->setOutputFile($logFile);

$configuration = json_decode(file_get_contents(__DIR__.'/configuration.json'));
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
                'quantity' => 1,
                'sku'=>'ITEM_OF_BUNDLE_3'
            ],
            'ITEM_OF_BUNDLE_4'=>[
                'cost' => 15,
                'description' => 'Item of bundle 2',
                'quantity' => 1,
                'sku'=>'ITEM_OF_BUNDLE_4'
            ]
        ]
    ]
];

var_dump($x->updateBundleLineItems($orderhiveProducts));