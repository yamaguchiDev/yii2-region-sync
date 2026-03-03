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
    }
}
