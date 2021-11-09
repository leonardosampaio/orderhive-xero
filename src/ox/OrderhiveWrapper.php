<?php

namespace ox;

class OrderhiveWrapper {

    private $app;

    private $maxRetries;
    private $timeBetweenRetries;


    public function __construct($app, $maxRetries, $timeBetweenRetries) {
        $this->app = $app;
        $this->maxRetries = $maxRetries;
        $this->timeBetweenRetries = $timeBetweenRetries;
    }

    /**
     * https://orderhive.docs.apiary.io/#reference/product/product-catalog/product-catalog
    */
    public function getProducts($cacheInMinutes)
    {
        Logger::getInstance()->log("Retrieving all Orderhive products");
        
        $starttime = microtime(true);

        $params = ['query'=>'','types'=>['1','7']];

        $cacheFile = __DIR__.'/../../cache/orderhive-products.json';
        $liveRequest = false;

        $result = [];

        if ($cacheInMinutes == 0 || !file_exists($cacheFile) || time() > json_decode(file_get_contents($cacheFile))->expires)
        {
            $liveRequest = true;

            $pageSize = 1000;
            $page = 0;
            $hasNextPage = false;

            $retries = 0;

            $bundles = [];

            do {
                $page++;

                $apiResponse = $this->app->post("/product/listing/flat?page=$page&size=$pageSize", $params);

                if (!isset($apiResponse['products']))
                {
                    Logger::getInstance()->log("Error retrieving Orderhive products");
                    Logger::getInstance()->log("Retrying in $this->timeBetweenRetries seconds");
                    sleep($this->timeBetweenRetries);

                    $page--;
                    $hasNextPage = true;
                    $retries++;

                    if ($retries > $this->maxRetries)
                    {
                        Logger::getInstance()->log("Error retrieving Orderhive products, maxRetries ($this->maxRetries) reached");
                        break;
                    }
                }
                else
                {
                    $hasNextPage = sizeof($apiResponse['products']) == $pageSize;

                    $products = [];

                    foreach($apiResponse['products'] as $product)
                    {
                        if (isset($product['sku']))
                        {
                            $products[trim($product['sku'])] = $product;
                        }

                        if (isset($product['productBundles']) && sizeof($product['productBundles'])>0)
                        {
                            foreach($product['productBundles'] as $productBundle)
                            {
                                $bundledProductId = $productBundle['bundledProductId'];
                                if (!isset($bundles[$bundledProductId]))
                                {
                                    $bundles[$bundledProductId] = [];
                                }

                                $bundles[$bundledProductId][] = [
                                    'sku'=>$product['sku'],
                                    'componentProductId'=>$productBundle['componentProductId'],
                                    'componentQuantity'=>$productBundle['componentQuantity']];
                            }
                        }
                    }

                    $result = array_merge($result, $products);
                }

            } while ($hasNextPage);

            foreach($result as &$product)
            {
                if (isset($bundles[$product['id']]))
                {
                    $product['bundle_of'] = $bundles[$product['id']];
                }
            }
        }
        else
        {
            $result = (json_decode(file_get_contents($cacheFile), true))['products'];
        }

        if ($cacheInMinutes !== 0 && $liveRequest)
        {
            file_put_contents($cacheFile, json_encode([
                'products'=>$result,
                'expires'=>strtotime("+$cacheInMinutes minutes")
            ]));
        }

        $endtime = microtime(true);

        return [
            'count'         =>  sizeof($result),
            'total_time'    =>  ($endtime - $starttime),
            'result'        =>  $result
        ];
    }
}