<?php

namespace yamaguchi\regionsync;

use yii\base\Module;

/**
 * regionsync module definition class
 */
class RegionSyncModule extends Module
{
    /**
     * @inheritdoc
     */
    public $controllerNamespace = 'yamaguchi\regionsync\controllers';

    /**
     * @var string Базовый URL главного сайта (например, https://yamaguchi.ru)
     */
    public $apiHost;

    /**
     * @var string Токен для авторизации запросов
     */
    public $apiToken;

    /**
     * @var string Секретный ключ для подписи запросов к API донора
     */
    public $apiSecret;

    /**
     * @var int|null geo_city_id текущего регионального сайта из таблицы storage_city.
     * Используется для фильтрации данных наличия при синхронизации с главным сайтом.
     * Пример: 71902 для Новосибирска, 71711 для Москвы.
     */
    public $geoCityId;

    /** @var bool Отключается для проверок изменяемых остатков без кэша. */
    public $useAvailabilityCache = true;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (\Yii::$app instanceof \yii\console\Application) {
            $this->controllerNamespace = 'yamaguchi\regionsync\commands';
            $this->controllerMap['product'] = \yamaguchi\regionsync\commands\ProductCommand::class;
        }

        // инициализация модуля
        if ($this->apiHost === null) {
            throw new \yii\base\InvalidConfigException('The "apiHost" property must be set.');
        }

        if ($this->apiToken === null) {
            throw new \yii\base\InvalidConfigException('The "apiToken" property must be set.');
        }

        if ($this->apiSecret === null) {
            throw new \yii\base\InvalidConfigException('The "apiSecret" property must be set.');
        }

        // Если geoCityId не задан явно, пытаемся определить его по домену (только для web)
        if ($this->geoCityId === null && \Yii::$app instanceof \yii\web\Application) {
            $this->resolveGeoCityId();
        }
    }

    /**
     * Пытается определить geoCityId на основе текущего хоста.
     * Логика: Host -> Subdomain -> Table sites (site_key) -> Table new_storage_city (system_name) -> geo_city_id
     */
    protected function resolveGeoCityId()
    {
        try {
            $this->geoCityId = $this->getGeoCityIdForHost(\Yii::$app->request->hostInfo);
        } catch (\Exception $e) {
            \Yii::error("Ошибка автоматического определения geoCityId: " . $e->getMessage(), __METHOD__);
        }
    }

    /** Точное сопоставление домена сайта с городом, без LIKE и случайного соседнего региона. */
    public function getGeoCityIdForHost(string $host): ?int
    {
        $host = strtolower((string)parse_url(strpos($host, '://') === false ? 'http://' . $host : $host, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $domains = [$host];
        if (substr($host, -4) === '.loc') {
            $domains[] = substr($host, 0, -4) . '.ru';
        }
        $ids = (new \yii\db\Query())->select('city.geo_city_id')->distinct()
            ->from(['site' => '{{%sites}}'])
            ->innerJoin(['city' => '{{%new_storage_city}}'], 'city.system_name = site.site_key')
            ->where(['site.domain' => $domains])->column();
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /**
     * Получить объект расчета наличия для товара в текущем регионе.
     *
     * @param int|string $itemId
     * @return services\AvailabilityResult|null
     */
    public function getProductAvailability($itemId, int $geoCityId = null, int $pointForSaleId = null)
    {
        $geoCityId = $geoCityId ?? (int)$this->geoCityId;
        if ($geoCityId <= 0) {
            return null;
        }
        $calculator = new services\AvailabilityCalculator();
        $calculator->useCache = $this->useAvailabilityCache;
        return $calculator->calculate((int)$itemId, $geoCityId, $pointForSaleId);
    }

    /**
     * Получить подробный отчёт по наличию товара в текущем или указанном регионе.
     *
     * @param int|string $itemId
     * @param int|null $geoCityId
     * @return array|null
     */
    public function checkProductAvailability($itemId, int $geoCityId = null)
    {
        $cityId = $geoCityId ?: (int)$this->geoCityId;

        if (!$cityId) {
            return null;
        }

        $calculator = new services\AvailabilityCalculator();
        $calculator->useCache = false;

        return $calculator->inspect((int)$itemId, $cityId);
    }
}
