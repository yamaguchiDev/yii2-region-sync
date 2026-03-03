<?php

namespace yamaguchi\regionsync\components;

use yamaguchi\regionsync\models\ProductVariant;
use Yii;
use yii\httpclient\Client;
use yamaguchi\regionsync\traits\SignedRequestTrait;

class PriceImporter
{
    use SignedRequestTrait;

    public function importForSite(array $site)
    {
        $client = $this->getHttpClient();
        $url = $site['url'] ?? $site['host']; // Поддержка старого и нового формата
        $errors = [];
        $updated = 0;

        // Получаем данные
        $dataFromYamaguchi = $this->fetchUrl($url);

        // Проверка формата данных
        if (!is_array($dataFromYamaguchi)) {
            return [
                'status' => 'error',
                'message' => 'Некорректный формат данных от источника',
                'updated' => 0
            ];
        }

        // Обработка каждого товара
        foreach ($dataFromYamaguchi as $item) {
            // Проверка обязательны х полей
            if (empty($item['itemId']) || !isset($item['price'])) {
                $errors[] = 'Отсутствует itemId или price для элемента: ' . json_encode($item);
                continue;
            }

            try {
                // Обновляем цену в БД (Оставляем привязку к локальным моделям сайта)
                $affectedRows = ProductVariant::updateAll(
                    [
                        'price' => $item['price'],
                        'priceAction' => $item['priceAction'] ?? null,
                    ],
                    ['itemId' => $item['itemId']]
                );

                if ($affectedRows > 0) {
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors[] = 'Ошибка обновления товара ' . $item['itemId'] . ': ' . $e->getMessage();
            }
        }

        // Отправляем статус (если не получилось - не критично)
        try {
            $path = 'regions/export-data/refrash-status-update';
            $endpoint = $site['host'] . '/regions/export-data/refrash-status-update';

            $statusUrl = $this->signedRequest($endpoint, $path);

            $client
                ->get($statusUrl, ['type' => $site['type'] ?? ''])
                ->addOptions([
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ])
                ->send();

        } catch (\Exception $e) {
            // Просто логируем, не прерываем выполнение
            Yii::warning('Не удалось отправить статус: ' . $e->getMessage(), __METHOD__);
        }

        // Очищаем кэш
        try {
            Yii::$app->cache->flush();
        } catch (\Exception $e) {
            Yii::warning('Не удалось очистить кэш: ' . $e->getMessage(), __METHOD__);
        }

        // Формируем результат
        $result = [
            'status' => 'ok',
            'site' => $site['id'] ?? 'unknown',
            'updated' => $updated,
        ];

        // Если были ошибки - добавляем их в ответ
        if (!empty($errors)) {
            $result['errors'] = $errors;
            $result['error_count'] = count($errors);
        }

        return $result;
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

    private function fetchUrl($url)
    {
        $client = $this->getHttpClient();
        $cacheKey = 'priceFromYamaguchi_' . md5($url);

        \Yii::$app->cache->delete($cacheKey);

        $path = 'regions/export-data/get-prices';
        $url = $this->signedRequest($url, $path);

        $data = \Yii::$app->cache->getOrSet(
            $cacheKey,
            function () use ($url, $client) {

                $response = $client->createRequest()
                    ->setMethod('GET')
                    ->setUrl($url)
                    ->addHeaders([
                        'User-Agent' => 'Yamaguchi Region Sync Module',
                    ])
                    ->send();

                if ($response->isOk) {
                    return json_decode($response->content, true);
                }

                return null;
            },
            3600
        );

        return $data;
    }
}
