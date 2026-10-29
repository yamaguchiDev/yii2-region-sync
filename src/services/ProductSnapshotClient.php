<?php

namespace yamaguchi\regionsync\services;

use Yii;
use yii\httpclient\Client;
use yamaguchi\regionsync\traits\SignedRequestTrait;

class ProductSnapshotClient
{
    use SignedRequestTrait;

    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'transport' => 'yii\httpclient\CurlTransport',
            'requestConfig' => [
                'options' => [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ],
            ],
        ]);
    }

    public function fetchOne($itemId = null, $productId = null, $includeFiles = true)
    {
        $query = ['includeFiles' => $includeFiles ? 1 : 0];
        if ($itemId !== null) {
            $query['itemId'] = (int)$itemId;
        } elseif ($productId !== null) {
            $query['productId'] = (int)$productId;
        } else {
            throw new ProductSnapshotException('Необходимо передать itemId или productId.');
        }

        return $this->request('regions/export-data/get-product-snapshot', $query);
    }

    public function fetchIds($cursor = 0, $limit = 100, $updatedAfter = null)
    {
        $query = ['cursor' => (int)$cursor, 'limit' => (int)$limit];
        if ($updatedAfter !== null && $updatedAfter !== '') {
            $query['updatedAfter'] = $updatedAfter;
        }
        return $this->request('regions/export-data/get-product-snapshot-ids', $query);
    }

    /** Published goods with exactly their position=0 variant; no unpublished/secondary SKUs. */
    public function fetchPrimaryItemIds($cursor = 0, $limit = 100)
    {
        return $this->request('regions/export-data/get-primary-item-ids', [
            'cursor' => (int)$cursor, 'limit' => (int)$limit,
        ]);
    }

    private function request($path, array $query)
    {
        $module = Yii::$app->getModule('regionsync');
        $url = rtrim($module->apiHost, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($query);
        $response = $this->client->createRequest()
            ->setMethod('GET')
            ->setUrl($this->signedRequest($url, $path))
            ->addHeaders(['Accept' => 'application/json', 'User-Agent' => 'YamaguchiRegionSync/1.0'])
            ->addOptions(['timeout' => 60, 'connectTimeout' => 10])
            ->send();

        if (!$response->isOk) {
            throw new ProductSnapshotException('Snapshot endpoint returned HTTP ' . $response->statusCode, $response->statusCode);
        }

        $data = json_decode($response->content, true);
        if (!is_array($data)) {
            throw new ProductSnapshotException('Snapshot endpoint returned invalid JSON.');
        }
        return $data;
    }
}
