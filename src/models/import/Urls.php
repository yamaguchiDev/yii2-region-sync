<?php

namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель URL
 */
class Urls extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%new_url}}';
    }

    /**
     * Поиск URL по алиасу
     */
    public static function findByAlias($alias)
    {
        return static::findOne(['alias' => $alias]);
    }

    public function rules()
    {
        return [
            ['alias', 'required'],
            ['alias', 'string', 'max' => 255],  // пример ограничений, подкорректируйте по необходимости
            ['alias', 'unique', 'targetAttribute' => 'alias', 'message' => 'Это значение alias уже используется.'],
        ];
    }

    /**
     * Поиск или создание URL
     */
    public static function findOrCreate($data)
    {
        // Ищем по алиасу
        $url = static::findByAlias($data['alias']);

        if ($url) {
            Yii::info("URL найден по алиасу: '{$data['alias']}' (ID={$url->id})", __METHOD__);
        } else {
            // Создаём новый URL
            $url = new static();
            $url->alias = $data['alias'];
            $url->route = $data['route'] ?? 'category/site/view';
            $url->param = $data['param'] ?? 0;
            $url->title = $data['title'] ?? '';
            $url->keywords = $data['keywords'] ?? '';
            $url->description = $data['description'] ?? '';
            $url->isAdaptive = $data['isAdaptive'] ?? 1;
            $url->status = $data['status'] ?? 1;
            $url->params_for_filter = $data['params_for_filter'] ?? null;
           // $url->no_index_search_results = $data['no_index_search_results'] ?? 0;

            if ($url->validate()) {
                if (!$url->save(false)) {
                    Yii::error('Ошибка создания URL: ' . print_r($url->errors, true), __METHOD__);
                    return null;
                }

                Yii::info("Создан новый URL: '{$data['alias']}' (ID={$url->id})", __METHOD__);
            }

        }

        return $url;
    }

    /**
     * Обновление данных URL
     */
    public function updateData($data)
    {
        $this->route = $data['route'] ?? $this->route;
        $this->param = $data['param'] ?? $this->param;
        $this->title = $data['title'] ?? $this->title;
        $this->keywords = $data['keywords'] ?? $this->keywords;
        $this->description = $data['description'] ?? $this->description;
        $this->isAdaptive = $data['isAdaptive'] ?? $this->isAdaptive;
        $this->status = $data['status'] ?? $this->status;
        $this->canonical = $data['canonical'] ?? $this->canonical;

        return $this->save(false);
    }

    /**
     * Поиск URL
     */
    public static function findByParamAndRoute($param, $route)
    {
        return static::findOne([
            'param' => $param,
            'route' => $route
        ]);
    }


    public static function createOrUpdate($data, $productId)
    {
        $url = static::findByParamAndRoute($productId, 'product/site/view');

        if (!$url) {
            $url = new static();
            $url->param = $productId;
            $url->route = 'product/site/view';
        }

        // Заполняем только существующие поля
        $tableFields = $url->attributes();
        foreach ($data as $key => $value) {
            if ($key == 'id') {
                continue;
            }
            if ($key == 'param') {
                continue;
            }

            if (in_array($key, $tableFields)) {
                $url->$key = $value;
            }
        }

        if($url->validate()){
            return $url->save(false);
        }

    }
}
