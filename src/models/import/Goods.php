<?php

namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;

/**
 * Модель товара
 */
class Goods extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%goods}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            // Обязательные поля
            [['name', 'model', 'brandId'], 'required'],

            // Числовые поля (integer)
            [['brandId', 'popular', 'action', 'actionSize', 'sticker', 'present',
                'leather', 'forChildren', 'isNew', 'isCategoryHit', 'isLanding',
                'discontinued', 'published', 'productPage', 'position', 'isPreorder',
                'isYandexXml', 'creditDiscount', 'isBestseller'], 'integer'],

            // Текстовые поля (string)
            [['uri', 'name', 'model', 'alterCategoryName', 'text', 'shortDescBottom',
                'desc', 'spec', 'specShort', 'related', 'promoSite', 'yandexDesc'], 'string'],

            // Уникальность URI
            ['uri', 'unique', 'message' => 'Такой URI уже существует'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'uri' => 'URI',
            'name' => 'Название',
            'model' => 'Модель',
            'brandId' => 'ID Бренда',
            'alterCategoryName' => 'Альтернативное название категории',
            'text' => 'Текст',
            'shortDescBottom' => 'Краткое описание (снизу)',
            'desc' => 'Описание',
            'spec' => 'Спецификация',
            'specShort' => 'Краткая спецификация',
            'popular' => 'Популярность',
            'action' => 'Акция',
            'actionSize' => 'Размер акции',
            'sticker' => 'Стикер',
            'present' => 'Подарок',
            'leather' => 'Кожа',
            'forChildren' => 'Для детей',
            'isNew' => 'Новинка',
            'isCategoryHit' => 'Хит категории',
            'isLanding' => 'Лендинг',
            'discontinued' => 'Снято с производства',
            'published' => 'Опубликовано',
            'productPage' => 'Страница товара',
            'related' => 'Связанные товары',
            'position' => 'Позиция',
            'promoSite' => 'Промо сайт',
            'isPreorder' => 'Предзаказ',
            'isYandexXml' => 'Yandex XML',
            'yandexDesc' => 'Описание для Yandex',
            'creditDiscount' => 'Скидка на кредит',
            'isBestseller' => 'Бестселлер',
        ];
    }

    /**
     * Получение списка полей таблицы
     */
    public function getTableFields()
    {
        return [
            'id', 'uri', 'name', 'model', 'brandId', 'alterCategoryName',
            'text', 'shortDescBottom', 'desc', 'spec', 'specShort', 'popular',
            'action', 'actionSize', 'sticker', 'present', 'leather', 'forChildren',
            'isNew', 'isCategoryHit', 'isLanding', 'discontinued', 'published',
            'productPage', 'related', 'position', 'promoSite', 'isPreorder',
            'isYandexXml', 'yandexDesc', 'creditDiscount', 'isBestseller'
        ];
    }

    /**
     * Сохранение товара с гибкой обработкой полей
     *
     * @param array $data Данные для импорта
     * @return bool
     */
    public function saveProduct($data)
    {
        // Поля, которые всегда пропускаем (служебные)
        $skipFields = [
            'id', 'variants', 'categories', 'attrs', 'image', 'Imagespng',
            'url', 'brand', 'categoryForCompare', 'categoryMainId',
            'categoryNameResult', 'nameMiddle', 'stiker', 'nameFull',
            'variant', 'quantity', 'quantity_basic_showrooms_summ',
            'quantity_manual', 'isCompare', 'availability', 'storage',
            'itemId', '_actionSize', '_sticker', '_present', '_leather',
            '_forChildren', '_isAvitoXml', 'exceptionForXml', 'view_before_pub',
            'hiden_price', 'cat_in_bread_crumbs', 'action_postion',
            'is_manual_popular', 'is_price_on_request', 'isNew_changed',
            'is_on_direct_link', 'category_id_for_compare', 'category_id_for_feed',
            'is_rent', 'multigallery', 'is_black_card', 'is_old', 'categoryId',
            'categoryParentId', 'variantId', 'extraBrand', 'created', 'updated',
            'deleted_at', 'slogan', 'font_size', 'benefit_phrase', 'notes',
            'desktop_video', 'mobile_video', 'md_description', 'slogan_color',
            'avitoDesc'
        ];

        // Получаем поля реальной таблицы
        $tableFields = $this->getTableFields();

        // Заполняем только поля, которые есть в таблице
        foreach ($data as $key => $value) {
            // Пропускаем служебные поля
            if (in_array($key, $skipFields)) {
                continue;
            }

            // Заполняем только если поле существует в таблице
            if (in_array($key, $tableFields)) {
                // Обработка null значений
                if ($value === 'null' || $value === null) {
                    $this->$key = null;
                } else {
                    $this->$key = $value;
                }
            }
        }

        $this->isLanding = 0;
        $this->published = 0;


        // Валидация и сохранение
        if ($this->validate()) {
            return $this->save();
        }

        // Логирование ошибок валидации
        Yii::error('Ошибки валидации товара: ' . print_r($this->errors, true), __METHOD__);

        return false;
    }

    /**
     * Обновление только указанных полей
     *
     * @param array $data Данные для обновления
     * @param array $fields Поля для обновления (если пусто - все доступные)
     * @return bool
     */
    public function updateFields($data, $fields = [])
    {
        if ($this->isNewRecord) {
            throw new \yii\base\InvalidCallException('Метод можно вызывать только для существующих записей');
        }

        // Если указаны конкретные поля - фильтруем данные
        if (!empty($fields)) {
            $data = array_intersect_key($data, array_flip($fields));
        }

        // Сохраняем только указанные поля
        return $this->saveProduct($data);
    }


    /**
     * Поиск товара по itemId
     */
    public static function findByItemId($itemId)
    {
        return static::find()
            ->alias('g')
            ->join('LEFT JOIN', 'new_product_variant np', 'np.productId = g.id')
            ->where([
                'np.itemId' => $itemId,
                'np.position' => 0,
            ])
            ->one();
    }

}
