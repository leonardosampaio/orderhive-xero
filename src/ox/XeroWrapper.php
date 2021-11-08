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

	function __construct($clientId, $clientSecret, $redirectUri) {

		$jsonTokenFile = __DIR__.'/../../token.json';

		$json = json_decode(file_get_contents($jsonTokenFile));

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

			file_put_contents($jsonTokenFile, json_encode($json));
		} 

		$config = Configuration::getDefaultConfiguration()->setAccessToken($json->token);		  
		$this->apiInstance = new AccountingApi(new Client(), $config);
   	}

	/**
	 * List all items by code (SKU)
	 */
	private function getAllItems()
	{
		//FIXME cache X minutes

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
			//FIXME error handling
			return null;
		}
		
	}

	/**
	 * Breaks down bundle items in individual line items
	 */
	public function updateBundleLineItems($orderhiveProducts = [], $invoiceId = null)
	{
		if (!is_array($orderhiveProducts) || empty($orderhiveProducts))
		{
			//FIXME log no orderhive products 
			return false;
		}
		
		$allInvoices = [];
		if ($invoiceId)
		{
			$getInvoiceResult = $this->apiInstance->getInvoice($this->xeroTenantId, $invoiceId);
			if ($getInvoiceResult instanceof Invoices)
			{
				array_push($allInvoices, $getInvoiceResult->getInvoices()[0]);
			}
			else
			{
				//FIXME error handling
			}
		}
		else
		{
			//get all from today
			$where = 'Date=DateTime('.date('Y, m, d').')';
			$page = 1;
			do
			{
				$getInvoicesResult = $this->apiInstance->getInvoices($this->xeroTenantId, null, $where, null, null, null, null, null, $page);
	
				$retrivedInvoices = [];
				if ($getInvoicesResult instanceof Invoices)
				{
					$retrivedInvoices = $getInvoicesResult->getInvoices();
					foreach($retrivedInvoices as $invoice)
					{
						//to get line items, first get the full object
						$getFullInvoiceResult = $this->apiInstance->getInvoice($this->xeroTenantId, $invoice->getInvoiceId());
						$fullInvoice = $getFullInvoiceResult->getInvoices()[0];
						array_push($allInvoices, $fullInvoice);
					}
					$page++;
				}
				else
				{
					//FIXME error handling
				}
			} while (sizeof($retrivedInvoices) >= 100);
		}

		if (empty($allInvoices))
		{
			//FIXME log no invoices found
			return false;
		}

		$items 					= $this->getAllItems();
		$invoicesToUpdateArray 	= [];
		foreach($allInvoices as $invoice)
		{
			$newLineItems 		= [];
			$lineItems 			= $invoice->getLineItems();

			foreach($lineItems as $k => $currentLineItem)
			{
				if (isset($orderhiveProducts[$currentLineItem->getItemCode()]) &&
					isset($orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of']))
				{
					//replace bundles by individual items
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

			//has bundles, update
			if (!empty($newLineItems))
			{
				$lineItems = array_merge($lineItems, $newLineItems);
				$invoice->setLineItems($lineItems);
				$invoicesToUpdateArray[] = $invoice;
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
				//FIXME error handling
			}
		}

		return false;
	}
}
?>
