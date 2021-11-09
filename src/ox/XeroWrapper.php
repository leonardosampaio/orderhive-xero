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

		if (!file_exists($jsonTokenFile))
		{
			die("$jsonTokenFile not found");
		}

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
	private function getAllItems($cacheInMinutes)
	{
		Logger::getInstance()->log("Retrieving all Xero items");

		$cacheFile = __DIR__.'/../../cache/xero-items.json';
		$liveRequest = false;

		$result = [];

		if ($cacheInMinutes == 0 || !file_exists($cacheFile) || time() > json_decode(file_get_contents($cacheFile))->expires)
        {
			$liveRequest = true;

			$getItemsResult = $this->apiInstance->getItems($this->xeroTenantId);

			if ($getItemsResult instanceof Items)
			{
				foreach($getItemsResult->getItems() as $item)
				{
					$result[$item->getCode()] = $item;
				}
			}
			else
			{
				Logger::getInstance()->log("Error retrieving Xero items");
				Logger::getInstance()->log(json_encode($getItemsResult));
			}
		}
		else
		{
            $result = (json_decode(file_get_contents($cacheFile)))->items;
        }

		if ($cacheInMinutes !== 0 && $liveRequest)
        {
            file_put_contents($cacheFile, json_encode([
                'items'=>$result,
                'expires'=>strtotime("+$cacheInMinutes minutes")
            ]));
        }

		return $result;
	}

	private function createItem($code, $name)
	{
		Logger::getInstance()->log("Creating item $code $name");

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
			Logger::getInstance()->log("Error creating item $code $name");
			Logger::getInstance()->log(json_encode($result));
			return false;
		}
		
	}

	/**
	 * Breaks down bundle items in individual line items
	 */
	public function updateBundleLineItems($cacheInMinutes, $orderhiveProducts = [], $invoiceId = null)
	{
		if (!is_array($orderhiveProducts) || empty($orderhiveProducts))
		{
			Logger::getInstance()->log("No Orderhive products, exiting");
			return false;
		}
		
		$allInvoices = [];
		if ($invoiceId)
		{
			$getInvoiceResult = $this->apiInstance->getInvoice($this->xeroTenantId, $invoiceId);
			if ($getInvoiceResult instanceof Invoices)
			{
				Logger::getInstance()->log("Retrieved full invoice $invoiceId");
				array_push($allInvoices, $getInvoiceResult->getInvoices()[0]);
			}
			else
			{
				Logger::getInstance()->log("Error retrieving full invoice $invoiceId");
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
					Logger::getInstance()->log("Retrieved all invoices from today");

					$retrivedInvoices = $getInvoicesResult->getInvoices();
					foreach($retrivedInvoices as $invoice)
					{
						//to get line items, first get the full object
						$getFullInvoiceResult = $this->apiInstance->getInvoice($this->xeroTenantId, $invoice->getInvoiceId());
						if ($getFullInvoiceResult instanceof Invoices)
						{
							Logger::getInstance()->log("Retrieved full invoice $invoiceId");
							$fullInvoice = $getFullInvoiceResult->getInvoices()[0];
							array_push($allInvoices, $fullInvoice);
						}
						else
						{
							Logger::getInstance()->log("Error retrieving full invoice $invoiceId");
						}
					}
					$page++;
				}
				else
				{
					Logger::getInstance()->log("Error retrieving all invoices from today");
				}
			} while (sizeof($retrivedInvoices) >= 100);
		}

		if (empty($allInvoices))
		{
			Logger::getInstance()->log("No invoices retrieved, exiting");
			return false;
		}

		$items 					= $this->getAllItems($cacheInMinutes);
		$invoicesToUpdateArray 	= [];
		foreach($allInvoices as $invoice)
		{
			Logger::getInstance()->log("Processing invoice " . $invoice->getInvoiceId());

			$newLineItems 		= [];
			$lineItems 			= $invoice->getLineItems();

			foreach($lineItems as $k => $currentLineItem)
			{
				if (isset($orderhiveProducts[$currentLineItem->getItemCode()]) &&
					isset($orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of']))
				{
					Logger::getInstance()->log("Replacing bundle ".$currentLineItem->getItemCode()." by individual items");
					unset($lineItems[$k]);

					$bundleItems = $orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of'];
					foreach($bundleItems as $bundleItem)
					{
						if (!isset($items[$bundleItem['sku']]))
						{
							$this->createItem($bundleItem['sku'], $bundleItem['description']);
						}

						Logger::getInstance()->log("Registering new line item with " . $bundleItem['sku']);
						$newLineItem = new LineItem;
						$newLineItem
							->setDescription($bundleItem['description'])
							->setQuantity($bundleItem['componentQuantity'] * $currentLineItem->getQuantity())
							->setUnitAmount(round(
								($currentLineItem->getUnitAmount() / sizeof($bundleItems))/$bundleItem['componentQuantity'], 2))
							->setItemCode($bundleItem['sku'])
							->setAccountCode($currentLineItem->getAccountCode());
						$newLineItems[] = $newLineItem;
					}
				}
			}

			if (!empty($newLineItems))
			{
				$lineItems = array_merge($lineItems, $newLineItems);
				$invoice->setLineItems($lineItems);
				$invoicesToUpdateArray[] = $invoice;
			}
			else {
				Logger::getInstance()->log("Invoice does not have bundles, ignoring");
			}
		}

		if (!empty($invoicesToUpdateArray))
		{
			Logger::getInstance()->log("Updating ".sizeof($invoicesToUpdateArray)." invoice(s)");

			$invoicesToUpdate = new Invoices;
			$invoicesToUpdate->setInvoices($invoicesToUpdateArray);
			$updateResult = $this->apiInstance->updateOrCreateInvoices($this->xeroTenantId, $invoicesToUpdate);

			if ($updateResult instanceof Invoices)
			{
				Logger::getInstance()->log("Sucessfully updated ".sizeof($updateResult->getInvoices())." invoice(s)");
				return true;
			}
			else
			{
				Logger::getInstance()->log("Error updating invoice(s)");
				Logger::getInstance()->log(json_encode($updateResult));
				return false;
			}
		}

		return true;
	}
}
