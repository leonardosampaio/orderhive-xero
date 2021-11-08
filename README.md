# Xero Orderhive bundles syncronization

This application...
## Instructions

1. Install php7.4-cli and php7.4-curl

2. Copy configuration.json.dist to configuration.json (or edit the existing file) and put credentials and other environment values:

* logFile - relative path to file to output log 
* retries - times API calls should be retried before giving up
* timeBetweenRetries - time in seconds between retries 
* credentials
    * xero
        * client_id -
        * client_secret -
        * redirect_uri -
        * webhook_key -
        * tenant_id -
    * orderhive
        * id_token - API key
        * refresh_token - API refresh token
* phpTimezone - Timezone that should be considered for date manipulation (this value overrides php.ini date.timezone)

3. Deploy in the webserver...

## API References

<https://orderhive.docs.apiary.io/>

<https://developer.xero.com/documentation/api/accounting/overview/>