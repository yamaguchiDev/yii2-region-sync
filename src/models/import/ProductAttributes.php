<?php
namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель атрибутов товара
 * Таблица: {{%product_attributes}} (уже существует)
 */
class ProductAttributes extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%product_attribute}}';
    }

    /**
     * Поиск атрибута по товару и ID атрибута
     */
    public static function findByProductAndAttribute($productId, $attributeId)
    {
        return static::findOne([
            'product_id' => $productId,
            'attribute_id' => $attributeId
        ]);
    }

    /**
     * Создание или обновление атрибута
     */
    public static function createOrUpdate($data, $productId)
    {
        $attribute = static::findByProductAndAttribute($productId, $data['attribute_id']);
        
        if (!$attribute) {
            $attribute = new static();
            $attribute->product_id = $productId;
            $attribute->attribute_id = $data['attribute_id'];
        }
        
        $attribute->value = $data['value'];
        
        return $attribute->save(false);
    }
}
