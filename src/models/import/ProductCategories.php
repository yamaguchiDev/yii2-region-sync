<?php

namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель связей товаров с категориями
 */
class ProductCategories extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%product_category}}';
    }

    /**
     * Поиск связи по товару и категории
     */
    public static function findByProductAndCategory($productId, $categoryId)
    {
        return static::findOne([
            'productId' => $productId,
            'categoryId' => $categoryId
        ]);
    }

    public function rules()
    {
        return [
            [['productId', 'categoryId'], 'required'],
            [['productId', 'categoryId'], 'integer'],  // или другой тип в зависимости от БД
            [['productId', 'categoryId'], 'unique',
                'targetAttribute' => ['productId', 'categoryId'],
                'message' => 'Эта комбинация productId и categoryId уже существует.'
            ],
        ];
    }

    /**
     * Поиск или создание связи товара с категорией
     */
    public static function findOrCreate($productId, $categoryId, $data = [])
    {
        $link = static::findByProductAndCategory($productId, $categoryId);

        if ($link) {
            Yii::info("Связь товара (ID={$productId}) с категорией (ID={$categoryId}) уже существует", __METHOD__);
        } else {
            $link = new static();
            $link->productId = $productId;
            $link->categoryId = $categoryId;
        }

        // Заполняем данные
        $link->position = 0;

        if ($link->validate()) {
            if (!$link->save(false)) {
                Yii::error('Ошибка создания связи товара с категорией: ' . print_r($link->errors, true), __METHOD__);
                return null;
            }
        }


        if ($link->isNewRecord) {
            Yii::info("Создана связь товара (ID={$productId}) с категорией (ID={$categoryId})", __METHOD__);
        } else {
            Yii::info("Обновлена связь товара (ID={$productId}) с категорией (ID={$categoryId})", __METHOD__);
        }

        return $link;
    }
}
