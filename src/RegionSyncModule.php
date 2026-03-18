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

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

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
        $host = \Yii::$app->request->hostInfo;
        // Извлекаем часть между // и первым . (например, "murmansk" из "http://murmansk.site.com")
        preg_match("/\/\/(.*?)\./", $host, $matches);
        $subdomain = isset($matches[1]) ? $matches[1] : null;

        if (!$subdomain) {
            return;
        }

        try {
            // 1. Ищем site_key в таблице sites
            $site = (new \yii\db\Query())
                ->select(['site_key'])
                ->from('{{%sites}}')
                ->where(['like', 'domain', $subdomain])
                ->one();

            if ($site && !empty($site['site_key'])) {
                // 2. Ищем geo_city_id в new_storage_city по этому ключу
                $city = (new \yii\db\Query())
                    ->select(['geo_city_id'])
                    ->from('{{%new_storage_city}}')
                    ->where(['system_name' => $site['site_key']])
                    ->one();

                if ($city) {
                    $this->geoCityId = $city['geo_city_id'];
                }
            }
        } catch (\Exception $e) {
            \Yii::error("Ошибка автоматического определения geoCityId: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Получить объект расчета наличия для товара в текущем регионе.
     *
     * @param int|string $itemId
     * @return services\AvailabilityResult|null
     */
    public function getProductAvailability($itemId)
    {
        if (!$this->geoCityId) {
            return null;
        }
        $calculator = new services\AvailabilityCalculator();
        return $calculator->calculate((int)$itemId, (int)$this->geoCityId);
    }
}
