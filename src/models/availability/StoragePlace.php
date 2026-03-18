<?php

namespace yamaguchi\regionsync\models\availability;

use yii\db\ActiveRecord;

/**
 * Склад/точка продаж (локальная копия из главного сайта)
 *
 * @property int    $place_id
 * @property int    $geo_location_id
 * @property string $geo_location_name
 * @property int    $geo_city_id
 * @property string $point_for_sales_name
 * @property int    $town_city_id
 * @property int    $point_for_sales_id
 * @property string $place_name
 * @property string $type_code
 * @property int    $closed
 */
class StoragePlace extends ActiveRecord
{
    /** Основной склад */
    const TYPE_CODE_MAIN = 'main';
    /** Шоу-рум */
    const TYPE_CODE_SHOWROOM = 'show_room';

    /**
     * ID главного московского склада (M3, place_id = 5514)
     * Используется для определения deliveryFrom
     */
    const ID_MAIN = 5514;

    public static function tableName(): string
    {
        return '{{%new_storage_place}}';
    }
}
