<?php

require __DIR__ . '/vendor/autoload.php';

use ox\XeroWrapper;

$configuration = json_decode(file_get_contents(__DIR__.'/configuration.json'));
$clientId = $configuration->CLIENT_ID;
$clientSecret = $configuration->CLIENT_SECRET;
$redirectUri = $configuration->REDIRECT_URI;

$tokenJsonFile = __DIR__.'/trial.json';

$x = new XeroWrapper($clientId, $clientSecret, $redirectUri, $tokenJsonFile);

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