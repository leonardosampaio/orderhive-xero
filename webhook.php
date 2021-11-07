<?php

require __DIR__ . '/vendor/autoload.php';

use ox\XeroWrapper;

$rawPayload = file_get_contents('php://input');

$configuration = json_decode(file_get_contents(__DIR__.'/configuration.json'));
$clientId = $configuration->CLIENT_ID;
$clientSecret = $configuration->CLIENT_SECRET;
$redirectUri = $configuration->REDIRECT_URI;
$webhookKey = $configuration->webhook_key;

$wrapper = new XeroWrapper($clientId, $clientSecret, $redirectUri, $tokenJsonFile);

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

http_response_code(200);

$json = json_decode($rawPayload);

$orderhiveProducts = []; //FIXME cache X minutes

if (isset($json) && isset($json->events))
{
    foreach($json->events as $event)
    {
        if ($event->tenantId == $configuration->tenant_id &&
            $event->eventCategory == 'INVOICE' &&
            in_array($event->eventType, ['UPDATED','CREATED']))
        {
            $invoiceId = $event->resourceId;
            $wrapper->updateBundleLineItems($orderhiveProducts, $invoiceId);
        }
    }
}