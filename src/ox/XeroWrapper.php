<?php

namespace ox;

use XeroAPI\XeroPHP\Models\Accounting\Payment;
use XeroAPI\XeroPHP\Models\Accounting\Payments;
use XeroAPI\XeroPHP\Configuration;
use XeroAPI\XeroPHP\Api\AccountingApi;
use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\GenericProvider;
use XeroAPI\XeroPHP\ApiException;
use XeroAPI\XeroPHP\Models\Accounting\LineItem;
use XeroAPI\XeroPHP\Models\Accounting\Invoices;
use \XeroAPI\XeroPHP\Models\Accounting\Item;
use \XeroAPI\XeroPHP\Models\Accounting\Items;

class XeroWrapper
{
	private $apiInstance;

	private $xeroTenantId;
	private $jsonTokenFile;
	private $clientId;
	private $clientSecret;
	private $redirectUri;
	private $token;

	public function __construct($clientId, $clientSecret, $redirectUri)
	{
		$this->xeroTenantId = '';
		$this->clientId = $clientId;
		$this->clientSecret = $clientSecret;
		$this->redirectUri = $redirectUri;

		$this->jsonTokenFile = __DIR__.'/../../token.json';

		if (!file_exists($this->jsonTokenFile))
		{
			die("$this->jsonTokenFile not found");
		}

		$this->token = json_decode(file_get_contents($this->jsonTokenFile));
   	}

	public function getApiInstance()
	{
		if(time() > $this->token->expires_in)
		{
			$provider = new GenericProvider([
				'clientId'                => $this->clientId,   
				'clientSecret'            => $this->clientSecret,
				'redirectUri'             => $this->redirectUri,
				'urlAuthorize'            => 'https://login.xero.com/identity/connect/authorize',
				'urlAccessToken'          => 'https://identity.xero.com/connect/token',
				'urlResourceOwnerDetails' => 'https://identity.xero.com/resources'
				]);

			$newAccessToken = $provider->getAccessToken('client_credentials');

			$this->token->access_token = $newAccessToken->getToken();
			$this->token->expires_in = $newAccessToken->getExpires();

			file_put_contents($this->jsonTokenFile, json_encode($this->token));

			$this->apiInstance = null;
		} 

		if (!$this->apiInstance)
		{
			$config = Configuration::getDefaultConfiguration()->setAccessToken($this->token->access_token);
			$this->apiInstance = new AccountingApi(new Client(), $config);
		}

		return $this->apiInstance;
	}

	/**
	 * List all items by code (SKU)
	 */
	public function getAllItems($cacheInMinutes)
	{
		Logger::getInstance()->log("Retrieving all Xero items");

		$cacheFile = __DIR__.'/../../cache/xero-items.json';
		$liveRequest = false;

		$result = [];

		if ($cacheInMinutes == 0 || !file_exists($cacheFile) || time() > json_decode(file_get_contents($cacheFile))->expires)
        {
			$liveRequest = true;

			$getItemsResult = $this->getApiInstance()->getItems($this->xeroTenantId);
			sleep(1);

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

		try
		{
			$result = $this->getApiInstance()->updateOrCreateItems($this->xeroTenantId, $itemsToCreate, true);
			sleep(1);

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
		catch (ApiException $e)
		{
			Logger::getInstance()->log("Error creating item $code $name");
			Logger::getInstance()->log($e->getResponseBody());
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
			$getInvoiceResult = $this->getApiInstance()->getInvoice($this->xeroTenantId, $invoiceId);
			sleep(1);

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
				$getInvoicesResult = $this->getApiInstance()->getInvoices($this->xeroTenantId, null, $where, null, null, null, null, null, $page);
				sleep(1);
	
				$retrivedInvoices = [];
				if ($getInvoicesResult instanceof Invoices)
				{
					Logger::getInstance()->log("Retrieved all invoices from today");

					$retrivedInvoices = $getInvoicesResult->getInvoices();
					foreach($retrivedInvoices as $invoice)
					{
						//to get line items, first get the full object
						$getFullInvoiceResult = $this->getApiInstance()->getInvoice($this->xeroTenantId, $invoice->getInvoiceId());
						sleep(1);

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
		$paymentsToRecreate 	= [];
		foreach($allInvoices as $invoice)
		{
			Logger::getInstance()->log("Processing invoice " . $invoice->getInvoiceId());

			$newLineItems 		= [];
			$lineItems 			= $invoice->getLineItems();

			foreach($lineItems as $k => $currentLineItem)
			{
				Logger::getInstance()->log("Processing line item code " .
                                        $currentLineItem->getItemCode());

				if (isset($orderhiveProducts[$currentLineItem->getItemCode()]) &&
					isset($orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of']))
				{
					$deletedPayments = [];
					$invoicePayments = $invoice->getPayments();
					if (!empty($invoicePayments))
					{
						foreach($invoicePayments as $payment)
						{
							$paymentId = $payment->getPaymentId();
							$payment = new Payment;
							$payment->setPaymentID($paymentId)
									->setStatus(PAYMENT::STATUS_DELETED);

							$result = $this->apiInstance->deletePayment($this->xeroTenantId, $paymentId, $payment);
							sleep(1);

							if ($result instanceof Payments)
							{
								Logger::getInstance()->log("Deleted payment $paymentId");
								array_push($deletedPayments, $result->getPayments()[0]);
							}
							else
							{
								Logger::getInstance()->log("Error deleting payment $paymentId");
							}
						}

						if (!empty($deletedPayments))
						{
							//payments deleted, reload object
							$invoice = $this->apiInstance->getInvoice($this->xeroTenantId, $invoice->getInvoiceId())[0];
							sleep(1);

							if (sizeof($deletedPayments) === 1)
							{
								$paymentsToRecreate[$invoice->getInvoiceId()] = $deletedPayments[0];
							}
						}
					}

					Logger::getInstance()->log("Replacing bundle ".$currentLineItem->getItemCode()." by individual items");
					unset($lineItems[$k]);

					$bundleItems = $orderhiveProducts[$currentLineItem->getItemCode()]['bundle_of'];
					foreach($bundleItems as $bundleItem)
					{
						$name = trim($orderhiveProducts[$bundleItem['sku']]['name']);
						$bundleItem['description'] = strlen($name) > 50 ? substr($name, 0, 50) : $name;
						
						if (!isset($items->{$bundleItem['sku']}))
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
							->setAccountCode($currentLineItem->getAccountCode())
							->setTaxType($currentLineItem->getTaxType())
							->setDiscountRate($currentLineItem->getDiscountRate())
							->setTracking($currentLineItem->getTracking());
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
			try
			{
				$updateResult = $this->getApiInstance()->updateOrCreateInvoices($this->xeroTenantId, $invoicesToUpdate, true);
				sleep(1);
				
				if ($updateResult instanceof Invoices)
				{
					Logger::getInstance()->log("Sucessfully updated ".sizeof($updateResult->getInvoices())." invoice(s)");

					if (!empty($paymentsToRecreate))
					{
						Logger::getInstance()->log("Recreating ".sizeof($paymentsToRecreate)." payments(s)");

						$updatedInvoicesTotals = [];
						foreach($updateResult as $invoice)
						{
							$updatedInvoicesTotals[$invoice->getInvoiceId()] = $invoice->getTotal();
						}

						$newPaymentsObj = new Payments;
						$newPaymentsArr = [];

						foreach ($paymentsToRecreate as $invoiceId => $paymentToRecreate)
						{
							$newTotal = $updatedInvoicesTotals[$invoiceId];
							
							$newPayment = new Payment;
							$newPayment->setInvoice($paymentToRecreate->getInvoice())
								->setAccount($paymentToRecreate->getAccount())
								->setAmount($newTotal)
								->setReference($paymentToRecreate->getReference())
								->setDate($paymentToRecreate->getDate());
							$newPaymentsArr[] = $newPayment;
						}

						$newPaymentsObj->setPayments($newPaymentsArr);

						try {
							$result = $this->apiInstance->createPayment($this->xeroTenantId, $newPaymentsObj);
							sleep(1);

							if ($result instanceof Payments)
							{
								Logger::getInstance()->log(
									"Sucessfully recreated ".sizeof($result->getPayments())." payment(s)");
							}
							else
							{
								Logger::getInstance()->log("Error recreating payment(s)");
							}
						}
						catch (ApiException $e)
						{
							Logger::getInstance()->log("Error recreating payment(s)");
							Logger::getInstance()->log($e->getResponseBody());
						}
					}

					return true;
				}
				else
				{
					Logger::getInstance()->log("Error updating invoice(s)");
					Logger::getInstance()->log(json_encode($updateResult));
					return false;
				}
			}
			catch (ApiException $e)
			{
				Logger::getInstance()->log("Error updating invoice(s)");
				Logger::getInstance()->log($e->getResponseBody());
				return false;
			}
		}

		return true;
	}
}
