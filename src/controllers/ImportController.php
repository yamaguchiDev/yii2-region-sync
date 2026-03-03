<?php

namespace yamaguchi\regionsync\controllers;

use yii\web\Controller;
use yamaguchi\regionsync\components\PriceImporter;
use yamaguchi\regionsync\services\ProductService;
use Yii;
use yii\httpclient\Client;
use yii\web\Response;
use yamaguchi\regionsync\traits\SignedRequestTrait;

/**
 * regionsync/import
 */
class ImportController extends Controller
{
    use SignedRequestTrait;

    /** @var ProductService */
    private $productService;

    public function __construct($id, $module, ProductService $productService, $config = [])
    {
        $this->productService = $productService;
        parent::__construct($id, $module, $config);
    }

    /**
     * Запуск массового импорта (как было раньше)
     */
    public function actionRun($token)
    {
        $module = Yii::$app->getModule('regionsync');
        if ($token !== $module->apiToken) {
            throw new \yii\web\ForbiddenHttpException('Invalid token');
        }

        $site = [
            'id' => '1', // В модуле этот параметр может быть фиксированным или тоже из настроек
            'host' => $module->apiHost,
            'url' => $module->apiHost, 
        ];
        
        $importer = new PriceImporter();
        $result = $importer->importForSite($site);

        Yii::$app->response->format = Response::FORMAT_JSON;

        return $result;
    }

    /**
     * /regionsync/import/sync-one?itemId=267&token=
     *
     * Синхронизация одного товара по идентификатору
     */
    public function actionSyncOne($itemId, $token)
    {
        $module = Yii::$app->getModule('regionsync');

        if ($token !== $module->apiToken) {
            throw new \yii\web\ForbiddenHttpException('Invalid token');
        }

        $site = [
            'host' => $module->apiHost,
            'url' => $module->apiHost, 
        ];

        $product = $this->fetchItemDetails($itemId, $site);

        if (!$product || empty($product)) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return [
                'success' => false,
                'message' => 'Не удалось получить данные о товаре с удаленного сервера',
            ];
        }

        $process = $this->productService->importProduct(reset($product));

        Yii::$app->response->format = Response::FORMAT_JSON;

        if (isset($process['success']) && $process['success'] === false) {
            return [
                'success' => false,
                'message' => 'Ошибка на региональном сайте: ' . ($process['error'] ?? 'Неизвестная ошибка'),
                'data' => $process,
            ];
        }

        return [
            'success' => true,
            'message' => 'Успешно добавлено',
            'data' => $process,
        ];
    }

    private static $httpClient = null;

    private function getHttpClient()
    {
        if (self::$httpClient === null) {
            self::$httpClient = new Client([
                'transport' => 'yii\httpclient\CurlTransport',
                'requestConfig' => [
                    'options' => [
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                    ],
                ],
            ]);
        }

        return self::$httpClient;
    }

    /**
     * Запрашивает детали товара по itemId
     * @param string $itemId
     * @param array $site
     * @return array|null
     */
    private function fetchItemDetails($itemId, array $site)
    {
        $client = $this->getHttpClient();
        $path = 'regions/export-data/get-item-by-item-id';

        $endpoint = rtrim($site['host'], '/') . '/' . ltrim($path, '/') . '?itemId=' . urlencode($itemId);

        try {
            $signedUrl = $this->signedRequest($endpoint, $path);

            $response = $client->createRequest()
                ->setMethod('GET')
                ->setUrl($signedUrl)
                ->addHeaders([
                    'User-Agent' => 'YamaguchiImporter/1.0',
                    'Accept' => 'application/json',
                ])
                ->addOptions([
                    'timeout' => 10.0,
                    'connectTimeout' => 5.0,
                    CURLOPT_SSL_VERIFYPEER => false, 
                    CURLOPT_SSL_VERIFYHOST => false, 
                ])
                ->send();

            if (!$response->isOk) {
                Yii::warning("Эндпоинт вернул статус {$response->statusCode} для itemId=$itemId. Body: " . $response->content, __METHOD__);
                return null;
            }

            $data = json_decode($response->content, true);

            if (!is_array($data)) {
                Yii::warning("Некорректный JSON от эндпоинта для itemId=$itemId", __METHOD__);
                return null;
            }

            return $data;
        }
        catch (\Exception $e) {
            $errorMsg = "Ошибка запроса деталей для itemId={$itemId}.\n";
            $errorMsg .= "URL: {$endpoint}\n";
            $errorMsg .= "Error: " . $e->getMessage() . "\n";
            $errorMsg .= "Trace: " . $e->getTraceAsString();
            Yii::warning($errorMsg, __METHOD__);
            return null;
        }
    }
}
