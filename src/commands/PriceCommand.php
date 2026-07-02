<?php

namespace yamaguchi\regionsync\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yamaguchi\regionsync\components\PriceImporter;

/**
 * Консольная команда для обновления цен с главного сайта.
 *
 * Вызов для cron:
 * php yii regionsync/price/sync
 *
 * При необходимости host можно переопределить первым аргументом:
 * php yii regionsync/price/sync https://yamaguchi.ru
 */
class PriceCommand extends Controller
{
    public $defaultAction = 'sync';

    /**
     * Обновляет цены товаров с главного сайта.
     *
     * @param string|null $host Базовый URL донора, если нужно переопределить apiHost из конфига.
     * @return int
     */
    public function actionSync($host = null): int
    {
        /** @var \yamaguchi\regionsync\RegionSyncModule|null $module */
        $module = Yii::$app->getModule('regionsync');

        if ($module === null) {
            $this->stderr('[PriceSync] Ошибка: модуль regionsync не подключён.' . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $host = $host ?: $module->apiHost;

        if (empty($host)) {
            $this->stderr('[PriceSync] Ошибка: apiHost не задан.' . PHP_EOL);
            return ExitCode::CONFIG;
        }

        $host = rtrim($host, '/');
        $site = [
            'id' => 'cron',
            'host' => $host,
            'url' => $host,
        ];

        $this->stdout('[PriceSync] Старт обновления цен с ' . $host . PHP_EOL);

        try {
            $result = (new PriceImporter())->importForSite($site);
        } catch (\Exception $e) {
            Yii::error('[PriceSync] Ошибка обновления цен: ' . $e->getMessage(), __METHOD__);
            $this->stderr('[PriceSync] Ошибка: ' . $e->getMessage() . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $status = $result['status'] ?? 'unknown';
        $updated = $result['updated'] ?? 0;
        $publishedUpdated = $result['published_updated'] ?? 0;
        $skippedWithoutItemId = $result['skipped_without_item_id'] ?? 0;
        $errorCount = $result['error_count'] ?? 0;

        $this->stdout(
            '[PriceSync] Результат: status=' . $status
            . ', updated=' . $updated
            . ', published_updated=' . $publishedUpdated
            . ', skipped_without_item_id=' . $skippedWithoutItemId
            . ', error_count=' . $errorCount
            . PHP_EOL
        );

        if ($status !== 'ok') {
            $this->stderr('[PriceSync] Ошибка: ' . ($result['message'] ?? 'неизвестная ошибка') . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->stderr('[PriceSync] ' . $error . PHP_EOL);
            }

            return ExitCode::DATAERR;
        }

        $this->stdout('[PriceSync] Завершено ' . date('Y-m-d H:i:s') . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Алиас для совместимости с привычным именем действия.
     *
     * @param string|null $host
     * @return int
     */
    public function actionRun($host = null): int
    {
        return $this->actionSync($host);
    }
}
