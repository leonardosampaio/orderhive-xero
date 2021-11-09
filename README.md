# Orderhive bundles syncronization to Xero invoices

This application gets Invoice create/update events from Xero webhooks and updates line items with bundles, breaking down these bundle items in individual items.
## Instructions

1. Install php7.4-cli and php7.4-curl

2. Copy configuration.json.dist to configuration.json (or edit the existing file) and put credentials and other environment values:

* retries - times API calls should be retried before giving up
* timeBetweenRetries - time in seconds between retries 
* credentials
    * xero
        * client_id - App client id
        * client_secret - App client secret
        * redirect_uri - URL to redirect to after OAuth2 authencitation, usually https://domain.com/path/callback.php
        * webhook_key - App webhooks key
        * tenant_id - tenant id that should have invoices processed, get this from token.json after completing the OAuth2 authorization
    * orderhive
        * id_token - API key
        * refresh_token - API refresh token
* phpTimezone - Timezone that should be considered for date manipulation (this value overrides php.ini date.timezone)
* cacheInMinutes - Time in minutes to reuse previous calls to Orderhive product listing and Xero get items endpoints. Use 0 to disable cache.

3. Expose the folder /public on your webserver

4. Configure Delivery URL on Xero (developer.xero.com > App > Webhooks) to point to https://domain.com/path/webhook.php

5. Configure a cron job to call cli/webhook-worker.php

## How this works

Every webhook call is saved as a temporary JSON file to /cache, when webhook-worker.php is executed it checks the payloads previously received and updates, if needed, the line items of the invoices with bundles. Only webhooks with create/update events in Invoices are processed.

## API References

<https://orderhive.docs.apiary.io/>

<https://developer.xero.com/documentation/api/accounting/overview/>