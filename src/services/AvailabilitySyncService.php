<?php

namespace yamaguchi\regionsync\services;

use Yii;
use yii\httpclient\Client;
use yamaguchi\regionsync\models\availability\StorageCity;
use yamaguchi\regionsync\traits\SignedRequestTrait;

/**
 * Сервис синхронизации данных наличия с главного сайта.
 *
 * Запрашивает у главного сайта (yamaguchi.ru) пакет данных:
 *   - storage_place  (склады региона + московский главный)
 *   - storage_item   (справочник товаров)
 *   - storage_place_product (остатки)
 *   - storage_city   (мета-данные текущего региона)
 *
 * и сохраняет их в локальные таблицы регионального сайта.
 *
 * Вызывается из AvailabilityCommand::actionSync()
 *
 * На главном сайте должен быть реализован эндпоинт:
 *   GET /regions/export-data/get-availability?geoCityId={id}
 *   (подписанный через SignedRequestTrait)
 */
class AvailabilitySyncService
{
    use SignedRequestTrait;

    /** Путь к эндпоинту на главном сайте */
    const ENDPOINT_PATH = 'regions/export-data/get-availability';

    /** @var string Хост главного сайта */
    private $apiHost;

    /** @var int geo_city_id текущего регионального сайта */
    private $geoCityId;

    /** @var Client */
    private $httpClient;

    public function __construct(string $apiHost, int $geoCityId)
    {
        $this->apiHost   = rtrim($apiHost, '/');
        $this->geoCityId = $geoCityId;
        $this->httpClient = new Client([
            'transport'     => 'yii\httpclient\CurlTransport',
            'requestConfig' => [
                'options' => [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ],
            ],
        ]);
    }

    /**
     * Выполнить полную синхронизацию данных наличия
     *
     * @return array{success: bool, message: string, counts: array}
     */
    public function sync(): array
    {
        // 1. Получаем данные с главного сайта
        $data = $this->fetchData();
        if ($data === null) {
            return ['success' => false, 'message' => 'Не удалось получить данные с главного сайта', 'counts' => []];
        }

        // 2. Сохраняем данные в локальные таблицы
        $counts = [];
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $counts['storage_place']         = $this->upsertStoragePlaces($data['storage_place'] ?? []);
            $counts['storage_item']          = $this->upsertStorageItems($data['storage_item'] ?? []);
            $counts['storage_place_product'] = $this->upsertStoragePlaceProducts($data['storage_place_product'] ?? []);
            $this->upsertStorageCity($data['storage_city'] ?? []);

            // Обновляем timestamp
            Yii::$app->db->createCommand(
                'UPDATE {{%storage_city}} SET products_updated_at = NOW(), places_updated_at = NOW() WHERE geo_city_id = :id',
                [':id' => $this->geoCityId]
            )->execute();

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error('AvailabilitySyncService error: ' . $e->getMessage(), __CLASS__);
            return ['success' => false, 'message' => $e->getMessage(), 'counts' => []];
        }

        // 3. Сбрасываем кэш
        try {
            $cache = Yii::$app->cache ?? null;
            if ($cache) {
                $cache->flush();
            }
        } catch (\Exception $e) {
            Yii::warning('Cache flush failed: ' . $e->getMessage(), __CLASS__);
        }

        return ['success' => true, 'message' => 'OK', 'counts' => $counts];
    }

    /**
     * Запрашивает данные у главного сайта
     */
    private function fetchData(): ?array
    {
        $path     = self::ENDPOINT_PATH;
        $endpoint = $this->apiHost . '/' . $path . '?geoCityId=' . $this->geoCityId;

        try {
            $signedUrl = $this->signedRequest($endpoint, $path);

            $response = $this->httpClient->createRequest()
                ->setMethod('GET')
                ->setUrl($signedUrl)
                ->addHeaders([
                    'User-Agent' => 'YamaguchiAvailabilitySync/1.0',
                    'Accept'     => 'application/json',
                ])
                ->addOptions([
                    'timeout'        => 60.0,
                    'connectTimeout' => 10.0,
                ])
                ->send();

            if (!$response->isOk) {
                Yii::error(
                    "AvailabilitySync: HTTP {$response->statusCode} from $endpoint",
                    __CLASS__
                );
                return null;
            }

            $data = json_decode($response->content, true);
            if (!is_array($data)) {
                Yii::error("AvailabilitySync: Invalid JSON from $endpoint", __CLASS__);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Yii::error("AvailabilitySync fetch error: " . $e->getMessage(), __CLASS__);
            return null;
        }
    }

    /**
     * Upsert storage_place
     * @param array[] $places
     */
    private function upsertStoragePlaces(array $places): int
    {
        if (empty($places)) return 0;

        $count = 0;
        foreach ($places as $place) {
            Yii::$app->db->createCommand('
                INSERT INTO {{%storage_place}}
                    (place_id, geo_location_id, geo_location_name, geo_city_id,
                     point_for_sales_name, town_city_id, point_for_sales_id,
                     place_name, type_code, closed)
                VALUES
                    (:place_id, :geo_location_id, :geo_location_name, :geo_city_id,
                     :point_for_sales_name, :town_city_id, :point_for_sales_id,
                     :place_name, :type_code, :closed)
                ON DUPLICATE KEY UPDATE
                    geo_location_id    = VALUES(geo_location_id),
                    geo_location_name  = VALUES(geo_location_name),
                    geo_city_id        = VALUES(geo_city_id),
                    point_for_sales_name = VALUES(point_for_sales_name),
                    town_city_id       = VALUES(town_city_id),
                    point_for_sales_id = VALUES(point_for_sales_id),
                    place_name         = VALUES(place_name),
                    type_code          = VALUES(type_code),
                    closed             = VALUES(closed)
            ', [
                ':place_id'            => (int)$place['place_id'],
                ':geo_location_id'     => $place['geo_location_id'] ?? null,
                ':geo_location_name'   => $place['geo_location_name'] ?? null,
                ':geo_city_id'         => $place['geo_city_id'] ?? null,
                ':point_for_sales_name'=> $place['point_for_sales_name'] ?? null,
                ':town_city_id'        => $place['town_city_id'] ?? null,
                ':point_for_sales_id'  => $place['point_for_sales_id'] ?? null,
                ':place_name'          => $place['place_name'] ?? null,
                ':type_code'           => $place['type_code'] ?? null,
                ':closed'              => (int)($place['closed'] ?? 0),
            ])->execute();
            $count++;
        }
        return $count;
    }

    /**
     * Upsert storage_item
     * @param array[] $items
     */
    private function upsertStorageItems(array $items): int
    {
        if (empty($items)) return 0;

        $count = 0;
        foreach ($items as $item) {
            Yii::$app->db->createCommand('
                INSERT INTO {{%storage_item}}
                    (item_id, item_title, price, soon, netto_weight_item, gross_weight_item, npresence_comment)
                VALUES
                    (:item_id, :item_title, :price, :soon, :netto, :gross, :comment)
                ON DUPLICATE KEY UPDATE
                    item_title          = VALUES(item_title),
                    price               = VALUES(price),
                    soon                = VALUES(soon),
                    netto_weight_item   = VALUES(netto_weight_item),
                    gross_weight_item   = VALUES(gross_weight_item),
                    npresence_comment   = VALUES(npresence_comment)
            ', [
                ':item_id'   => (int)$item['item_id'],
                ':item_title'=> $item['item_title'] ?? null,
                ':price'     => isset($item['price']) ? (int)$item['price'] : null,
                ':soon'      => $item['soon'] ?? null,
                ':netto'     => isset($item['netto_weight_item']) ? (float)$item['netto_weight_item'] : null,
                ':gross'     => isset($item['gross_weight_item']) ? (float)$item['gross_weight_item'] : null,
                ':comment'   => $item['npresence_comment'] ?? null,
            ])->execute();
            $count++;
        }
        return $count;
    }

    /**
     * Upsert storage_place_product
     * @param array[] $products
     */
    private function upsertStoragePlaceProducts(array $products): int
    {
        if (empty($products)) return 0;

        $count = 0;
        foreach ($products as $product) {
            Yii::$app->db->createCommand('
                INSERT INTO {{%storage_place_product}}
                    (place_id, item_id, quantity, _point_for_sales_id, _place_type_id, created)
                VALUES
                    (:place_id, :item_id, :quantity, 0, 0, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity = VALUES(quantity),
                    created  = NOW()
            ', [
                ':place_id' => (int)$product['place_id'],
                ':item_id'  => (int)$product['item_id'],
                ':quantity' => (int)$product['quantity'],
            ])->execute();
            $count++;
        }
        return $count;
    }

    /**
     * Upsert storage_city
     * @param array $city
     */
    private function upsertStorageCity(array $city): void
    {
        if (empty($city) || empty($city['geo_city_id'])) {
            return;
        }

        Yii::$app->db->createCommand('
            INSERT INTO {{%storage_city}}
                (geo_city_id, geo_location_id, geo_location_name, system_name)
            VALUES
                (:geo_city_id, :geo_location_id, :geo_location_name, :system_name)
            ON DUPLICATE KEY UPDATE
                geo_location_id   = VALUES(geo_location_id),
                geo_location_name = VALUES(geo_location_name),
                system_name       = VALUES(system_name)
        ', [
            ':geo_city_id'       => (int)$city['geo_city_id'],
            ':geo_location_id'   => $city['geo_location_id'] ?? null,
            ':geo_location_name' => $city['geo_location_name'] ?? null,
            ':system_name'       => $city['system_name'] ?? null,
        ])->execute();
    }
}
