<?php

namespace yamaguchi\regionsync\components;

use yamaguchi\regionsync\models\ProductVariant;
use Yii;
use yii\httpclient\Client;
use yii\helpers\FileHelper;
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
        $publishedUpdated = 0;
        $skippedWithoutItemId = 0;

        // Получаем данные
        $dataFromYamaguchi = $this->fetchUrl($url);

        // Проверка формата данных
        if (!is_array($dataFromYamaguchi)) {
            $this->logPriceUpdateEvent('fetch_error', [
                'source_url' => $url,
                'message' => 'Некорректный формат данных от источника',
            ]);

            return [
                'status' => 'error',
                'message' => 'Некорректный формат данных от источника',
                'updated' => 0
            ];
        }

        $this->logIncomingItemIds($dataFromYamaguchi, $url);
        $this->logPriceUpdateEvent('start', [
            'site' => $site['id'] ?? 'unknown',
            'source_url' => $url,
            'total' => count($dataFromYamaguchi),
        ]);

        // Обработка каждого товара
        foreach ($dataFromYamaguchi as $item) {
            // Часть исторических записей приходит без itemId и не может быть сопоставлена локально.
            if (empty($item['itemId'])) {
                $skippedWithoutItemId++;
                continue;
            }

            if (!isset($item['price'])) {
                $errors[] = 'Отсутствует price для элемента: ' . json_encode($item);
                continue;
            }

            try {
                // Обновляем цену в БД (Оставляем привязку к локальным моделям сайта)
                $priceAffectedRows = ProductVariant::updateAll(
                    [
                        'price' => $item['price'],
                        'priceAction' => $item['priceAction'] ?? null,
                    ],
                    ['itemId' => $item['itemId']]
                );

                $publishedAffectedRows = 0;
                if (array_key_exists('published', $item)) {
                    $publishedAffectedRows = $this->updatePublishedByItemId($item['itemId'], $item['published']);
                    $publishedUpdated += $publishedAffectedRows;
                }

                if ($priceAffectedRows > 0 || $publishedAffectedRows > 0) {
                    $updated++;
                    $this->logPriceUpdateEvent('price_updated', [
                        'itemId' => $item['itemId'],
                        'price' => $item['price'],
                        'priceAction' => $item['priceAction'] ?? null,
                        'published' => $item['published'] ?? null,
                        'price_affected_rows' => $priceAffectedRows,
                        'published_affected_rows' => $publishedAffectedRows,
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = 'Ошибка обновления товара ' . $item['itemId'] . ': ' . $e->getMessage();
                $this->logPriceUpdateEvent('update_error', [
                    'itemId' => $item['itemId'],
                    'price' => $item['price'],
                    'priceAction' => $item['priceAction'] ?? null,
                    'message' => $e->getMessage(),
                ]);
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
            'published_updated' => $publishedUpdated,
            'skipped_without_item_id' => $skippedWithoutItemId,
        ];

        // Если были ошибки - добавляем их в ответ
        if (!empty($errors)) {
            $result['errors'] = $errors;
            $result['error_count'] = count($errors);
        }

        $this->logPriceUpdateEvent('finish', [
            'site' => $result['site'],
            'updated' => $updated,
            'published_updated' => $publishedUpdated,
            'skipped_without_item_id' => $skippedWithoutItemId,
            'error_count' => count($errors),
        ]);

        return $result;
    }

    private function updatePublishedByItemId($itemId, $published)
    {
        return Yii::$app->db->createCommand(
            'UPDATE {{%goods}} g '
            . 'INNER JOIN {{%new_product_variant}} pv ON pv.productId = g.id '
            . 'SET g.published = :published '
            . 'WHERE pv.itemId = :itemId',
            [
                ':published' => (int)$published ? 1 : 0,
                ':itemId' => (int)$itemId,
            ]
        )->execute();
    }

    private function logPriceUpdateEvent($event, array $context = [])
    {
        try {
            $logDir = Yii::getAlias('@runtime/logs');
            FileHelper::createDirectory($logDir);
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'regionsync-price-updates.log';
            $row = array_merge([
                'date' => date('Y-m-d H:i:s'),
                'event' => $event,
            ], $context);

            file_put_contents(
                $logFile,
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Exception $e) {
            Yii::warning('Не удалось записать лог обновления цен: ' . $e->getMessage(), __METHOD__);
        }
    }

    private function logIncomingItemIds(array $items, $sourceUrl)
    {
        $itemIds = [];
        $skippedItems = [];
        $withoutItemId = 0;

        foreach ($items as $index => $item) {
            if (is_array($item) && !empty($item['itemId'])) {
                $itemIds[] = (string)$item['itemId'];
                continue;
            }

            $withoutItemId++;
            $skippedItems[] = [
                'index' => $index,
                'item' => $item,
            ];
        }

        try {
            $logDir = Yii::getAlias('@runtime/logs');
            FileHelper::createDirectory($logDir);
            $logFile = $logDir . DIRECTORY_SEPARATOR . 'regionsync-price-item-ids.log';
            $date = date('Y-m-d H:i:s');

            $header = sprintf(
                '[%s] source=%s total=%d with_item_id=%d without_item_id=%d unique_item_id=%d',
                $date,
                $sourceUrl,
                count($items),
                count($itemIds),
                $withoutItemId,
                count(array_unique($itemIds))
            );

            file_put_contents($logFile, $header . PHP_EOL, FILE_APPEND | LOCK_EX);

            file_put_contents(
                $logFile,
                sprintf('[%s] itemIds: %s', $date, implode(',', $itemIds)) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );

            if (!empty($skippedItems)) {
                $skippedLogFile = $logDir . DIRECTORY_SEPARATOR . 'regionsync-price-skipped-without-item-id.log';
                file_put_contents(
                    $skippedLogFile,
                    sprintf('[%s] source=%s skipped_without_item_id=%d', $date, $sourceUrl, count($skippedItems)) . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );

                foreach ($skippedItems as $skippedItem) {
                    file_put_contents(
                        $skippedLogFile,
                        json_encode($skippedItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );
                }
            }
        } catch (\Exception $e) {
            Yii::warning('Не удалось записать лог itemId импорта цен: ' . $e->getMessage(), __METHOD__);
        }
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
        $pathForUrl = '/' . ltrim($path, '/');
        $currentPath = parse_url($url, PHP_URL_PATH) ?: '';
        $endpoint = rtrim($url, '/');

        if (rtrim($currentPath, '/') !== $pathForUrl) {
            $endpoint .= $pathForUrl;
        }

        $url = $this->signedRequest($endpoint, $path);

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
