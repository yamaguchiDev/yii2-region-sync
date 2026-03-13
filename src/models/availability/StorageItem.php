<?php

namespace yamaguchi\regionsync\models\availability;

use yii\db\ActiveRecord;

/**
 * Справочник товаров из Борбозы (локальная копия)
 *
 * @property int    $item_id
 * @property string $item_title
 * @property int    $price
 * @property string $soon
 * @property float  $netto_weight_item
 * @property float  $gross_weight_item
 * @property string $npresence_comment
 */
class StorageItem extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%storage_item}}';
    }
}
