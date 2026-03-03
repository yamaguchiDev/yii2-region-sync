<?php

namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель категории
 */
class Category extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%category}}';
    }

    /**
     * Поиск категории по имени
     */
    public static function findByName($name)
    {
        return static::findOne(['name' => $name]);
    }

    /**
     * Поиск или создание категории
     */
    public static function findOrCreate($data)
    {
        // Ищем по имени
        $category = static::findByName($data['name']);

        if ($category) {
            Yii::info("Категория найдена по имени: '{$data['name']}' (ID={$category->id})", __METHOD__);
        } else {
            // Создаём новую категорию
            $category = new static();
            $category->name = $data['name'];
            $category->parent_id = $data['parent_id'] ?? 0;
            $category->brandId = $data['brandId'] ?? 0;
            $category->descShort = $data['descShort'] ?? null;
            $category->nameSingular = $data['nameSingular'] ?? $data['name'];
            $category->isCatalogDropDown = $data['isCatalogDropDown'] ?? 0;
            $category->typePrefix = $data['typePrefix'] ?? null;
            $category->googleProductCategory = $data['googleProductCategory'] ?? 0;
            $category->googleProductType = $data['googleProductType'] ?? null;
            $category->yandexSalesNotes = $data['yandexSalesNotes'] ?? null;

            if (!$category->save(false)) {
                Yii::error('Ошибка создания категории: ' . print_r($category->errors, true), __METHOD__);
                return null;
            }

            Yii::info("Создана новая категория: '{$data['name']}' (ID={$category->id})", __METHOD__);
        }

        return $category;
    }

    /**
     * Обновление данных категории
     */
    public function updateData($data)
    {
        $this->parent_id = $data['parent_id'] ?? $this->parent_id;
        $this->brandId = $data['brandId'] ?? $this->brandId;
        $this->descShort = $data['descShort'] ?? $this->descShort;
        $this->nameSingular = $data['nameSingular'] ?? $this->nameSingular;

        return $this->save(false);
    }
}
