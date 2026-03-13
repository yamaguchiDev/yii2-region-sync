<?php

namespace yamaguchi\regionsync\models\availability;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;

/**
 * Остатки товаров по складам (локальная копия из главного сайта)
 *
 * @property int      $_id
 * @property int      $place_id
 * @property int      $item_id
 * @property int      $quantity
 * @property int      $_point_for_sales_id
 * @property int      $_place_type_id
 * @property string   $created
 *
 * @property StoragePlace $storagePlace
 */
class StoragePlaceProduct extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%storage_place_product}}';
    }

    public function getStoragePlace(): ActiveQuery
    {
        return $this->hasOne(StoragePlace::class, ['place_id' => 'place_id']);
    }
}
