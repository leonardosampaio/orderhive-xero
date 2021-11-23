<?php

function post($url, $post = [], $body = '', $headers = [], $port = 443)
{
    $consumer = curl_init();

    if (!empty($post))
    {
        curl_setopt($consumer, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    if (!empty($headers))
    {
        curl_setopt($consumer, CURLOPT_HTTPHEADER, $headers);
    }

    if (!empty($body))
    {
        curl_setopt($consumer, CURLOPT_POSTFIELDS, $body);
    }

    curl_setopt($consumer, CURLOPT_URL, $url);
    curl_setopt($consumer, CURLOPT_PORT, $port);

    curl_setopt($consumer, CURLOPT_HEADER, 0);
    curl_setopt($consumer, CURLOPT_POST, 1);
    curl_setopt($consumer, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($consumer, CURLOPT_SSL_VERIFYPEER, 1);

    $response = curl_exec($consumer);
    $httpcode = curl_getinfo($consumer, CURLINFO_HTTP_CODE);

    if (curl_errno($consumer))
    {
        $response = curl_error($consumer);
        curl_close($consumer);
    }

    return [
        'status'=>$httpcode,
        'response'=>$response
    ];
}

if( !defined('STDIN') || !(empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0))
{
    die("This should run in CLI");
}

$url = 'https://identity.xero.com/connect/token';

$configuration = json_decode(file_get_contents(__DIR__.'/../configuration.json'));
$clientId = $configuration->credentials->xero->client_id;
$clientSecret = $configuration->credentials->xero->client_secret;

$result = post(
                $url,
                ['grant_type'=>
                        'client_credentials',
                'scope'=>
                        'accounting.settings accounting.settings.read accounting.transactions accounting.transactions.read'
                ], '', ['Authorization: Basic ' . base64_encode($clientId . ":" . $clientSecret)]);

var_dump($clientId);
var_dump($clientSecret);
var_dump($result);

file_put_contents(__DIR__.'/result.json', json_encode($result['response']));