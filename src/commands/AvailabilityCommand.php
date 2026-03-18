<?php

namespace yamaguchi\regionsync\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yamaguchi\regionsync\services\AvailabilitySyncService;

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
        $this->stdout("  storage_place:         {$counts['storage_place']} записей" . PHP_EOL);
        $this->stdout("  storage_item:          {$counts['storage_item']} записей" . PHP_EOL);
        $this->stdout("  storage_place_product: {$counts['storage_place_product']} записей" . PHP_EOL);
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
        $result = $calculator->calculate($itemId, $cityId);

        $this->stdout("itemId=$itemId, geoCityId={$cityId}" . PHP_EOL);
        $this->stdout("  availability:  {$result->availability}" . PHP_EOL);
        $this->stdout("  value:         " . ($result->value ?? 'null') . PHP_EOL);
        $this->stdout("  hasTestDrive:  " . ($result->hasTestDrive ? 'true' : 'false') . PHP_EOL);
        $this->stdout("  deliveryFrom:  " . ($result->deliveryFrom ?? 'null') . PHP_EOL);
        $this->stdout("  title:         " . $result->getTitle() . PHP_EOL);

        return ExitCode::OK;
    }
}
