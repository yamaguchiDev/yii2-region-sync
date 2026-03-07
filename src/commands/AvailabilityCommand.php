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
     * Опции:
     *   --no-cache  — не использовать кэш-сброс после синхронизации (для отладки)
     *
     * @return int
     */
    public function actionSync(): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        if (!$module->geoCityId) {
            $this->stderr("Ошибка: параметр geoCityId не задан в конфиге модуля 'regionsync'." . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $this->stdout("[AvailabilitySync] Старт синхронизации для geo_city_id={$module->geoCityId}" . PHP_EOL);
        $this->stdout("[AvailabilitySync] Хост: {$module->apiHost}" . PHP_EOL);

        $service = new AvailabilitySyncService($module->apiHost, $module->geoCityId);
        $result  = $service->sync();

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
     * Проверяет текущее наличие товара (без синхронизации с сервером).
     *
     * Пример: php yii availability/check 1234
     *
     * @param int $itemId
     * @return int
     */
    public function actionCheck(int $itemId): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule $module */
        $module = Yii::$app->getModule('regionsync');

        if (!$module->geoCityId) {
            $this->stderr("Ошибка: geoCityId не задан" . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $calculator = new \yamaguchi\regionsync\services\AvailabilityCalculator();
        $calculator->useCache = false;
        $result = $calculator->calculate($itemId, $module->geoCityId);

        $this->stdout("itemId=$itemId, geoCityId={$module->geoCityId}" . PHP_EOL);
        $this->stdout("  availability:  {$result->availability}" . PHP_EOL);
        $this->stdout("  value:         " . ($result->value ?? 'null') . PHP_EOL);
        $this->stdout("  hasTestDrive:  " . ($result->hasTestDrive ? 'true' : 'false') . PHP_EOL);
        $this->stdout("  deliveryFrom:  " . ($result->deliveryFrom ?? 'null') . PHP_EOL);
        $this->stdout("  title:         " . $result->getTitle() . PHP_EOL);

        return ExitCode::OK;
    }
}
