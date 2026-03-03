<?php

namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель вариантов товара
 * Таблица: {{%variants}} (уже существует)
 */
class Variants extends ActiveRecord
{

    public function rules()
    {
        return [
            [['itemId'], 'required'],
            [['position'], 'integer'],
            [
                ['itemId'],
                'unique',
                'targetAttribute' => ['itemId', 'position'],
                'filter' => ['position' => 0],
                'message' => 'Комбинация itemId и position=0 должна быть уникальной.'
            ],
            // другие правила валидации...
        ];
    }


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%new_product_variant}}';
    }

    /**
     * Поиск варианта по товару и variantId
     */
    public static function findByProductAndVariant($productId, $variantId)
    {
        return static::findOne([
            'productId' => $productId,
            'variantId' => $variantId
        ]);
    }

    /**
     * Создание или обновление варианта
     */
    public static function createOrUpdate($data, $productId)
    {
        $variant = static::findByProductAndVariant($productId, $data['variantId']);

        if (!$variant) {
            $variant = new static();
            $variant->productId = $productId;
            $variant->variantId = $data['variantId'];
        }

        foreach ($data as $key => $value) {
            if ($key === 'id') {
                continue; // пропускаем ключ 'id'
            }

            if ($key === 'productId') {
                continue; // пропускаем ключ 'id'
            }
            if (property_exists($variant, $key) || $variant->hasAttribute($key)) {
                $variant->$key = $value;
            }
        }

        if ($variant->validate()) {
            return $variant->save();
        }

        return false;

    }
}
