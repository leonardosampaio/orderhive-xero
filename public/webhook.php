<?php

$configurationFile = __DIR__.'/../configuration.json';
if (!file_exists($configurationFile))
{
    http_response_code(400);
    die("Error: $configurationFile not found");
}
$configuration = json_decode(file_get_contents($configurationFile));

$rawPayload = file_get_contents('php://input');

// Compute the payload with HMACSHA256 with base64 encoding
$computedSignatureKey = base64_encode(
    hash_hmac('sha256', $rawPayload, $configuration->credentials->xero->webhook_key, true)
);
  
// Signature key from Xero request
$xeroSignatureKey = isset($_SERVER['HTTP_X_XERO_SIGNATURE']) ?
    $_SERVER['HTTP_X_XERO_SIGNATURE'] : null;

// Response HTTP status code when:
//   200: Correctly signed payload
//   401: Incorrectly signed payload
if (!$xeroSignatureKey || !hash_equals($computedSignatureKey, $xeroSignatureKey))
{
    http_response_code(401);
    die();
}

$payloadJson = json_decode($rawPayload);
$payloadFile = 
    __DIR__.'/../cache/webhook-payload-'.rand(100000,999999).'-'.date('Ymd_His').'.json';

if ($payloadJson && isset($payloadJson->events) && touch($payloadFile))
{
    file_put_contents($payloadFile, $rawPayload);
    http_response_code(200);
}
else {
    http_response_code(400);
}
die();