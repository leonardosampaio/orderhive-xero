<?php
	ini_set('display_errors', 'On');
	require __DIR__ . '/../vendor/autoload.php';

	use ox\StorageClass;

	$configurationFile = __DIR__.'/../configuration.json';
	if (!file_exists($configurationFile)) {
		die($configurationFile . ' not found');
	}
	$configuration = json_decode(file_get_contents($configurationFile));
	
	// Storage Classe uses sessions for storing access token > extend to your DB of choice
	$storage = new StorageClass(__DIR__.'/../token.json');

	$provider = new \League\OAuth2\Client\Provider\GenericProvider([
        'clientId'                => $configuration->credentials->xero->clientId,   
        'clientSecret'            => $configuration->credentials->xero->clientSecret,
        'redirectUri'             => $configuration->credentials->xero->redirectUri,
        'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
        'urlAccessToken'          => 'https://identity.xero.com/connect/token',
        'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
    ]);

	$options = [
		'scope' => ['openid email profile offline_access assets projects accounting.settings accounting.transactions accounting.contacts accounting.journals.read accounting.reports.read accounting.attachments']
	];

	// Fetch the authorization URL from the provider; this returns the urlAuthorize option and generates and applies any necessary parameters (e.g. state).
	$authorizationUrl = $provider->getAuthorizationUrl($options);

	// Get the state generated for you and store it to the session.
	$_SESSION['oauth2state'] = $provider->getState();

	// Redirect the user to the authorization URL.
	header('Location: ' . $authorizationUrl);
	exit();