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
    }
}
