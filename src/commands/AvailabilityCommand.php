<?php

namespace yamaguchi\regionsync\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\httpclient\Client;
use yamaguchi\regionsync\services\AvailabilitySyncService;
use yamaguchi\regionsync\traits\SignedRequestTrait;

/**
 * Консольная команда для синхронизации данных наличия с главного сайта.
 *
 * Настройка в config/console.php регионального сайта:
 * ```php
 * 'controllerMap' => [
 *     'availability' => [
 *         'class' => 'yamaguchi\regionsync\commands\AvailabilityCommand',
 *     ],
 * ],
 * ```
 *
 * Вызов:
 * ```
 * D:/OpenServer/modules/php/PHP_7.4/php yii availability/sync
 * ```
 *
 * Крон (каждый час):
 * ```
 * 0 * * * * /usr/bin/php /var/www/region/data/www/yamaguchi-region.ru/yii availability/sync >> /dev/null 2>&1
 * ```
 */
class AvailabilityCommand extends Controller
{
    use SignedRequestTrait;

    public $defaultAction = 'sync';

    /**
     * Синхронизирует данные наличия с главного сайта.
     *
     * @param int|null $geoCityId ID города (если не задан в конфиге)
     * @return int
     */
    public function actionSync(int $geoCityId = null): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        $cityId = $geoCityId ?: $module->geoCityId;

        if (!$cityId) {
            $this->stderr("Ошибка: параметр geoCityId не задан. Передайте его аргументом: yii availability/sync <geoCityId>" . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $this->stdout("[AvailabilitySync] Старт синхронизации для geo_city_id={$cityId}" . PHP_EOL);
        $this->stdout("[AvailabilitySync] Хост: {$module->apiHost}" . PHP_EOL);

        $service = new AvailabilitySyncService($module->apiHost, $cityId);
        $result  = $service->sync();
// ... (остальное без изменений)

        if (!$result['success']) {
            $this->stderr("[AvailabilitySync] ОШИБКА: {$result['message']}" . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $counts = $result['counts'];
        $this->stdout("[AvailabilitySync] Успешно:" . PHP_EOL);
        $this->stdout("  new_storage_place:         " . ($counts['new_storage_place'] ?? 0) . " записей" . PHP_EOL);
        $this->stdout("  new_storage_item:          " . ($counts['new_storage_item'] ?? 0) . " записей" . PHP_EOL);
        $this->stdout("  new_storage_place_product: " . ($counts['new_storage_place_product'] ?? 0) . " записей" . PHP_EOL);
        $this->stdout("[AvailabilitySync] Завершено " . date('Y-m-d H:i:s') . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Синхронизирует данные наличия для ВСЕХ активных городов из storage_city (главный сайт).
     *
     * Используется при первоначальном заполнении или массовом обновлении данных.
     * Подходит для запуска вручную или как редкий крон (раз в сутки).
     *
     * Пример: php yii availability/sync-all
     *
     * @return int
     */
    public function actionSyncAll(): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        // Получаем список всех активных городов из таблицы new_storage_city
        // или напрямую с главного сайта (если local таблица пуста)
        $cities = \Yii::$app->db->createCommand(
            'SELECT geo_city_id, COALESCE(geo_location_name, system_name, geo_city_id) as name
             FROM {{%new_storage_city}} ORDER BY geo_city_id'
        )->queryAll();

        if (empty($cities)) {
            $this->stderr("[SyncAll] Таблица new_storage_city пуста. Добавьте города или сначала запустите sync для одного города." . PHP_EOL);
            return ExitCode::DATAERR;
        }

        $this->stdout("[SyncAll] Найдено городов: " . count($cities) . PHP_EOL);

        $ok      = 0;
        $errors  = 0;
        $startAll = microtime(true);

        foreach ($cities as $city) {
            $geoCityId = (int)$city['geo_city_id'];
            $name      = $city['name'];

            $this->stdout("[SyncAll] Синхронизация: $name (geo_city_id=$geoCityId) ... ");

            $service = new AvailabilitySyncService($module->apiHost, $geoCityId);
            $result  = $service->sync();

            if ($result['success']) {
                $c = $result['counts'];
                $this->stdout("OK"
                    . " place=" . ($c['new_storage_place']         ?? 0)
                    . " item=" . ($c['new_storage_item']          ?? 0)
                    . " product=" . ($c['new_storage_place_product'] ?? 0)
                    . PHP_EOL
                );
                $ok++;
            } else {
                $this->stderr(" ОШИБКА: {$result['message']}" . PHP_EOL);
                $errors++;
            }

            // Небольшая пауза, чтобы не перегружать главный сайт
            usleep(200000); // 0.2 сек
        }

        $elapsed = round(microtime(true) - $startAll, 1);
        $this->stdout("[SyncAll] Завершено за {$elapsed}с. OK=$ok ОШИБОК=$errors" . PHP_EOL);

        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Автоматически загружает список всех активных городов с главного сайта
     * и сохраняет их в локальную таблицу new_storage_city.
     *
     * Это первый шаг при настройке нового регионального сайта.
     *
     * Пример: php yii availability/seed-cities
     *
     * @return int
     */
    public function actionSeedCities(): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        $this->stdout("[SeedCities] Запрос списка городов у {$module->apiHost}..." . PHP_EOL);

        $service = new AvailabilitySyncService($module->apiHost, 0);
        $result  = $service->seedCities();

        if (!$result['success']) {
            $this->stderr("[SeedCities] ОШИБКА: {$result['message']}" . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("[SeedCities] Успешно загружено и сохранено {$result['count']} городов." . PHP_EOL);
        $this->stdout("[SeedCities] Теперь вы можете запустить 'php yii availability/sync-all' для полной синхронизации." . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Проверяет текущее наличие товара (без синхронизации с сервером).
     *
     * Пример: php yii availability/check 1234
     *         php yii availability/check 1234 71744
     *
     * @param int $itemId
     * @param int|null $geoCityId ID города (если не задан в конфиге)
     * @return int
     */
    public function actionCheck(int $itemId, int $geoCityId = null): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        $cityId = $geoCityId ?: $module->geoCityId;

        if (!$cityId) {
            $this->stderr("Ошибка: geoCityId не задан. Передайте его вторым параметром: yii availability/check $itemId <geoCityId>" . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $calculator = new \yamaguchi\regionsync\services\AvailabilityCalculator();
        $calculator->useCache = false;
        $report = $calculator->inspect($itemId, $cityId);

        $this->stdout("itemId=$itemId, geoCityId={$cityId}" . PHP_EOL);
        $this->stdout("  isDiscontinued:" . ($report['isDiscontinued'] ? ' true' : ' false') . PHP_EOL);
        $this->stdout("  isPreorder:    " . ($report['isPreorder'] ? 'true' : 'false') . PHP_EOL);
        $this->stdout("  sumInRegion:   " . $report['sumInRegion'] . PHP_EOL);
        $this->stdout("  sumOnShowroom: " . $report['sumOnShowroom'] . PHP_EOL);
        $this->stdout("  availability:  {$report['availability']}" . PHP_EOL);
        $this->stdout("  value:         " . ($report['value'] ?? 'null') . PHP_EOL);
        $this->stdout("  hasTestDrive:  " . ($report['hasTestDrive'] ? 'true' : 'false') . PHP_EOL);
        $this->stdout("  deliveryFrom:  " . ($report['deliveryFrom'] ?? 'null') . PHP_EOL);
        $this->stdout("  isAvailable:   " . ($report['isAvailable'] ? 'true' : 'false') . PHP_EOL);
        $this->stdout("  title:         " . $report['title'] . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Сравнивает локальные остатки товара с текущим ответом донора.
     *
     * Пример: php yii availability/compare 188 67118
     *
     * @param int $itemId
     * @param int $geoCityId
     * @return int
     */
    public function actionCompare(int $itemId, int $geoCityId): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        if (!$module) {
            $this->stderr('Ошибка: модуль regionsync не подключён.' . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $remoteData = $this->fetchRemoteAvailabilityData($module->apiHost, $geoCityId);
        if ($remoteData === null) {
            $this->stderr('Ошибка: не удалось получить данные наличия с донора.' . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $remoteRows = $this->filterPlaceProductsByItemId($remoteData['storage_place_product'] ?? [], $itemId);
        $localRows = $this->getLocalPlaceProducts($itemId, $geoCityId);

        $remoteByPlace = $this->indexQuantityByPlace($remoteRows);
        $localByPlace = $this->indexQuantityByPlace($localRows);

        $allPlaceIds = array_values(array_unique(array_merge(array_keys($remoteByPlace), array_keys($localByPlace))));
        sort($allPlaceIds);

        $missingLocal = [];
        $extraLocal = [];
        $quantityDiff = [];

        foreach ($allPlaceIds as $placeId) {
            $hasRemote = array_key_exists($placeId, $remoteByPlace);
            $hasLocal = array_key_exists($placeId, $localByPlace);

            if ($hasRemote && !$hasLocal) {
                $missingLocal[$placeId] = $remoteByPlace[$placeId];
                continue;
            }

            if (!$hasRemote && $hasLocal) {
                $extraLocal[$placeId] = $localByPlace[$placeId];
                continue;
            }

            if ((int)$remoteByPlace[$placeId] !== (int)$localByPlace[$placeId]) {
                $quantityDiff[$placeId] = [
                    'remote' => (int)$remoteByPlace[$placeId],
                    'local' => (int)$localByPlace[$placeId],
                ];
            }
        }

        $remoteSum = array_sum($remoteByPlace);
        $localSum = array_sum($localByPlace);

        $calculator = new \yamaguchi\regionsync\services\AvailabilityCalculator();
        $calculator->useCache = false;
        $report = $calculator->inspect($itemId, $geoCityId);

        $this->stdout("itemId={$itemId}, geoCityId={$geoCityId}" . PHP_EOL);
        $this->stdout('remote rows: ' . count($remoteRows) . ', sum: ' . $remoteSum . PHP_EOL);
        $this->stdout('local rows:  ' . count($localRows) . ', sum: ' . $localSum . PHP_EOL);
        $this->stdout('missing_local: ' . count($missingLocal) . PHP_EOL);
        $this->printPlaceQuantities($missingLocal);
        $this->stdout('extra_local: ' . count($extraLocal) . PHP_EOL);
        $this->printPlaceQuantities($extraLocal);
        $this->stdout('quantity_diff: ' . count($quantityDiff) . PHP_EOL);
        $this->printQuantityDiff($quantityDiff);
        $this->stdout('calculated availability: ' . $report['availability'] . PHP_EOL);
        $this->stdout('calculated title: ' . $report['title'] . PHP_EOL);

        return empty($missingLocal) && empty($extraLocal) && empty($quantityDiff) && (int)$remoteSum === (int)$localSum
            ? ExitCode::OK
            : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Логирует наличие товара по всем городам из new_storage_city.
     *
     * Пример: php yii availability/log-item 188
     */
    public function actionLogItem(int $itemId): int
    {
        $cities = Yii::$app->db->createCommand(
            'SELECT geo_city_id, COALESCE(geo_location_name, system_name, geo_city_id) AS name
             FROM {{%new_storage_city}}
             ORDER BY geo_location_name, geo_city_id'
        )->queryAll();

        if (empty($cities)) {
            $this->stderr("[LogItem] Таблица new_storage_city пуста. Сначала выполните sync/seed-cities." . PHP_EOL);
            return ExitCode::DATAERR;
        }

        $calculator = new \yamaguchi\regionsync\services\AvailabilityCalculator();
        $calculator->useCache = false;

        foreach ($cities as $city) {
            $geoCityId = (int)$city['geo_city_id'];
            $result = $calculator->calculate($itemId, $geoCityId);

            $message = sprintf(
                '[RegionSync][ItemAvailability] itemId=%d geoCityId=%d city="%s" availability=%s value=%s hasTestDrive=%s deliveryFrom=%s title="%s"',
                $itemId,
                $geoCityId,
                $city['name'],
                $result->availability,
                $result->value === null ? 'null' : $result->value,
                $result->hasTestDrive ? 'true' : 'false',
                $result->deliveryFrom === null ? 'null' : $result->deliveryFrom,
                $result->getTitle()
            );

            Yii::info($message, 'regionsync.availability');
            $this->stdout($message . PHP_EOL);
        }

        return ExitCode::OK;
    }

    private function fetchRemoteAvailabilityData(string $apiHost, int $geoCityId): ?array
    {
        $path = AvailabilitySyncService::ENDPOINT_PATH;
        $endpoint = rtrim($apiHost, '/') . '/' . $path . '?geoCityId=' . $geoCityId;
        $signedUrl = $this->signedRequest($endpoint, $path);

        try {
            $client = new Client([
                'transport' => 'yii\httpclient\CurlTransport',
                'requestConfig' => [
                    'options' => [
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                    ],
                ],
            ]);

            $response = $client->createRequest()
                ->setMethod('GET')
                ->setUrl($signedUrl)
                ->addHeaders([
                    'User-Agent' => 'YamaguchiAvailabilityCompare/1.0',
                    'Accept' => 'application/json',
                ])
                ->addOptions([
                    'timeout' => 60.0,
                    'connectTimeout' => 10.0,
                ])
                ->send();

            if (!$response->isOk) {
                $this->stderr("HTTP {$response->statusCode} from {$endpoint}" . PHP_EOL);
                return null;
            }

            $data = json_decode($response->content, true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return null;
        }
    }

    private function filterPlaceProductsByItemId(array $rows, int $itemId): array
    {
        $result = [];

        foreach ($rows as $row) {
            if ((int)($row['item_id'] ?? 0) === $itemId) {
                $result[] = [
                    'place_id' => (int)$row['place_id'],
                    'item_id' => (int)$row['item_id'],
                    'quantity' => (int)$row['quantity'],
                ];
            }
        }

        return $result;
    }

    private function getLocalPlaceProducts(int $itemId, int $geoCityId): array
    {
        return Yii::$app->db->createCommand(
            'SELECT spp.place_id, spp.item_id, spp.quantity
             FROM {{%new_storage_place_product}} spp
             INNER JOIN {{%new_storage_place}} sp ON sp.place_id = spp.place_id
             WHERE spp.item_id = :itemId
               AND sp.geo_city_id = :geoCityId
               AND spp.quantity > 0
             ORDER BY spp.place_id',
            [
                ':itemId' => $itemId,
                ':geoCityId' => $geoCityId,
            ]
        )->queryAll();
    }

    private function indexQuantityByPlace(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $placeId = (int)$row['place_id'];
            if (!isset($result[$placeId])) {
                $result[$placeId] = 0;
            }
            $result[$placeId] += (int)$row['quantity'];
        }

        return $result;
    }

    private function printPlaceQuantities(array $items): void
    {
        foreach ($items as $placeId => $quantity) {
            $this->stdout("  place_id={$placeId}, quantity={$quantity}" . PHP_EOL);
        }
    }

    private function printQuantityDiff(array $items): void
    {
        foreach ($items as $placeId => $quantity) {
            $this->stdout("  place_id={$placeId}, remote={$quantity['remote']}, local={$quantity['local']}" . PHP_EOL);
        }
    }
}
