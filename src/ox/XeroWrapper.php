<?php

namespace ox;

use XeroAPI\XeroPHP\Configuration;
use XeroAPI\XeroPHP\Api\AccountingApi;
use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\GenericProvider;
use XeroAPI\XeroPHP\Models\Accounting\LineItem;
use XeroAPI\XeroPHP\Models\Accounting\Invoices;
use \XeroAPI\XeroPHP\Models\Accounting\Item;
use \XeroAPI\XeroPHP\Models\Accounting\Items;

class XeroWrapper
{
	private $apiInstance;
	private $xeroTenantId;

	function __construct($clientId, $clientSecret, $redirectUri, $tokenJsonFile) {

		$json = json_decode(file_get_contents($tokenJsonFile));

		$this->xeroTenantId = $json->tenant_id;

		if(time() > $json->expires)
		{
			$provider = new GenericProvider([
				'clientId'                => $clientId,   
				'clientSecret'            => $clientSecret,
				'redirectUri'             => $redirectUri,
				'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
				'urlAccessToken'          => 'https://identity.xero.com/connect/token',
				'urlResourceOwnerDetails' => 'https://api.xero.com/api.xro/2.0/Organisation'
			]);
	
			$newAccessToken = $provider->getAccessToken('refresh_token', [
				'refresh_token' => $json->refresh_token
			]);

			$json->token = $newAccessToken->getToken();
			$json->expires = $newAccessToken->getExpires();
			$json->refresh_token = $newAccessToken->getRefreshToken();
			$json->id_token = $newAccessToken->getValues()["id_token"];

			file_put_contents($tokenJsonFile, json_encode($json));
		} 

		$config = Configuration::getDefaultConfiguration()->setAccessToken($json->token);		  
		$this->apiInstance = new AccountingApi(new Client(), $config);
   	}

	/**
	 * List all items by code (SKU)
	 */
	private function getAllItems()
	{
		$result = [];
		$getItemsResult = $this->apiInstance->getItems($this->xeroTenantId);
		foreach($getItemsResult->getItems() as $item)
		{
			$result[$item->getCode()] = $item;
		}
		return $result;
	}

	private function createItem($code, $name)
	{
		$item = new Item();
		$item->setName($name);
		$item->setCode($code);
		$item->setIsSold(true);

		$itemsToCreate = new Items();
		$itemsToCreate->setItems([$item]);

		$result = $this->apiInstance->updateOrCreateItems($this->xeroTenantId, $itemsToCreate);

		if ($result instanceof Items)
		{
			return true;
		}
		else
		{
			//TODO error handling
			return null;
		}
		
	}

	/**
	 * Breaks down bundle items in individual line items
	 */
	public function updateBundleLineItems($orderhiveProducts = [])
	{
		if (!is_array($orderhiveProducts) || empty($orderhiveProducts))
		{
			return false;
		}

		$items = $this->getAllItems();

		//$where = 'Status=="VOIDED"';
		$where = 'Date=DateTime(2021, 10, 13)';
		$allInvoices = [];
		$page = 1;
		$retry = false;
		do
		{
			$getInvoicesResult = $this->apiInstance->getInvoices($this->xeroTenantId, null, $where, null, null, null, null, null, $page);

			if ($getInvoicesResult instanceof Invoices)
			{
				$retrivedInvoices = $getInvoicesResult->getInvoices(); 
				$allInvoices = array_merge($allInvoices, $retrivedInvoices);
				$page++;
			}
			else
			{
				//TODO error handling
				$retry = true;
			}
		} while ($retry || sizeof($retrivedInvoices) >= 100);

		$invoicesToUpdateArray = [];
		foreach($allInvoices as $invoice)
		{
			$shouldUpdate = false;

			$getFullInvoiceResult = $this->apiInstance->getInvoice($this->xeroTenantId, $invoice->getInvoiceId());
			$fullInvoice = $getFullInvoiceResult->getInvoices()[0];
			$lineItems = $fullInvoice->getLineItems();

			$newLineItems = [];
			foreach($lineItems as $k => $currentLineItem)
			{
				if (isset($orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of']))
				{
					$shouldUpdate = true;

					unset($lineItems[$k]);

					foreach($orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of'] as $bundleItem)
					{
						if (!isset($items[$bundleItem['sku']]))
						{
							$this->createItem($bundleItem['sku'], $bundleItem['description']);
						}

						$newLineItem = new LineItem;
						$newLineItem
							->setDescription($bundleItem['description'])
							->setQuantity($bundleItem['quantity'] * $currentLineItem->getQuantity())
							->setUnitAmount($bundleItem['cost'])
							->setItemCode($bundleItem['sku'])
							->setAccountCode($currentLineItem->getAccountCode());
						$newLineItems[] = $newLineItem;
					}
				}
			}

			if ($shouldUpdate)
			{
				$lineItems = array_merge($lineItems, $newLineItems);
				$fullInvoice->setLineItems($lineItems);
				$invoicesToUpdateArray[] = $fullInvoice;
			}
		}

		if (!empty($invoicesToUpdateArray))
		{
			$invoicesToUpdate = new Invoices;
			$invoicesToUpdate->setInvoices($invoicesToUpdateArray);
			$updateResult = $this->apiInstance->updateOrCreateInvoices($this->xeroTenantId, $invoicesToUpdate);

			if ($updateResult instanceof Invoices)
			{
				return true;
			}
			else {
				//TODO error handling
			}
		}

		return false;
	}
}
?>
