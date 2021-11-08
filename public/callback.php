<?php
    ini_set('display_errors', 'On');

    require __DIR__ . '/../vendor/autoload.php';

    use ox\StorageClass;
    
    $configurationFile = __DIR__.'/../configuration.json';
    if (!file_exists($configurationFile)) {
        die($configurationFile . ' not found');
    }
    $configuration = json_decode(file_get_contents($configurationFile));

    // Storage Classe uses sessions for storing token > extend to your DB of choice
    $storage = new StorageClass(__DIR__.'/../token.json');

    $provider = new \League\OAuth2\Client\Provider\GenericProvider([
        'clientId'                => $configuration->credentials->xero->clientId,   
        'clientSecret'            => $configuration->credentials->xero->clientSecret,
        'redirectUri'             => $configuration->credentials->xero->redirectUri,
        'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
        'urlAccessToken'          => 'https://identity.xero.com/connect/token',
        'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
    ]);
   
    // If we don't have an authorization code then get one
    if (!isset($_GET['code'])) {
        echo "Something went wrong, no authorization code found";
        header("Location: index.php?error=true");
        exit();

    // Check given state against previously stored one to mitigate CSRF attack
    } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        echo "Invalid State";
        unset($_SESSION['oauth2state']);
        exit('Invalid state');
    } else {
        try {
            // Try to get an access token using the authorization code grant.
            $accessToken = $provider->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);
          
            $jwt = new XeroAPI\XeroPHP\JWTClaims();
            $jwt->setTokenId($accessToken->getValues()["id_token"]);
            $jwt->decode();
       
            $config = XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()->setAccessToken( (string)$accessToken->getToken() );
            $identityInstance = new XeroAPI\XeroPHP\Api\IdentityApi(
                new GuzzleHttp\Client(),
                $config
            );
            
            // Get Array of Tenant Ids
            $result = $identityInstance->getConnections();

            $tenantId = $result[0]->getTenantId();
            if ($tenantId != $configuration->credentials->xero->tenant_id)
            {
                die("Unexpected tenant_id ($tenantId), check your configuration file");
            }

            // Save my token, expiration and tenant_id
            $storage->setToken(
                $accessToken->getToken(),
                $accessToken->getExpires(),
                $tenantId, 
                $accessToken->getRefreshToken(),
                $accessToken->getValues()["id_token"]
            );
   
            //FIXME sucess
            header('Location: ' . './get.php');
            exit();
     
        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            echo "Failed!!!";
            // Failed to get the access token or user details.
            exit($e->getMessage());
        }
    }
?>
    <html>
    <head>
        <title>My App</title>
    </head>
    <body>      
        Opps! Should have redirected to <a href="get.php">to this page</a>
    </body>
</html>
