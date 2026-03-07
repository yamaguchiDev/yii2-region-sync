<?php

namespace yamaguchi\regionsync\models\availability;

use yii\db\ActiveRecord;

/**
 * Справочник регионов (локальная копия из главного сайта)
 *
 * @property int    $id
 * @property int    $geo_city_id
 * @property int    $geo_location_id
 * @property string $geo_location_name
 * @property string $system_name
 * @property string $products_updated_at
 * @property string $places_updated_at
 */
class StorageCity extends ActiveRecord
{
    /** geo_city_id Москвы в геобазе */
    const GEO_CITY_ID_MOSCOW = 71711;

    public static function tableName(): string
    {
        return '{{%storage_city}}';
    }
}
