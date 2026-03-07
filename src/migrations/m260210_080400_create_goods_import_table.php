<?php

use yii\db\Migration;

/**
 * Добавляет недостающие поля в существующую таблицу {{%goods}}
 */
class m260210_080400_create_goods_import_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        // Проверяем существование колонок перед добавлением
        $table = '{{%goods}}';

        // === Булевы поля (используем smallInteger для совместимости) ===
        $this->addColumnIfNotExists($table, '_actionSize', $this->string(255)->comment('Размер акции'));
        $this->addColumnIfNotExists($table, '_sticker', $this->string(255)->comment('Стикер'));
        $this->addColumnIfNotExists($table, '_present', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Подарок (0/1)'));
        $this->addColumnIfNotExists($table, '_leather', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Кожа (0/1)'));
        $this->addColumnIfNotExists($table, '_forChildren', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Для детей (0/1)'));

        // Avito
        $this->addColumnIfNotExists($table, '_isAvitoXml', $this->smallInteger(1)->unsigned()->defaultValue(1)->comment('Экспорт в Avito XML (0/1)'));
        $this->addColumnIfNotExists($table, 'avitoDesc', $this->string(3000)->comment('Описание для Avito'));

        // XML
        $this->addColumnIfNotExists($table, 'exceptionForXml', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Исключение для XML (0/1)'));

        // Публикация
        $this->addColumnIfNotExists($table, 'view_before_pub', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Просмотр до публикации (0/1)'));

        // Цена
        $this->addColumnIfNotExists($table, 'hiden_price', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Скрытая цена (0/1)'));

        // Хлебные крошки
        $this->addColumnIfNotExists($table, 'cat_in_bread_crumbs', $this->integer()->defaultValue(0)->comment('Категория в хлебных крошках'));

        // Позиция акции
        $this->addColumnIfNotExists($table, 'action_postion', $this->integer()->defaultValue(0)->comment('Позиция акции'));

        // Даты
        $this->addColumnIfNotExists($table, 'created', $this->dateTime()->notNull()->defaultExpression('NOW()')->comment('Дата создания'));
        $this->addColumnIfNotExists($table, 'updated', $this->dateTime()->notNull()->defaultExpression('NOW()')->append('ON UPDATE NOW()')->comment('Дата обновления'));

        // Слоган
        $this->addColumnIfNotExists($table, 'slogan', $this->string(100)->comment('Слоган'));
        $this->addColumnIfNotExists($table, 'font_size', $this->integer()->comment('Размер шрифта слогана'));
        $this->addColumnIfNotExists($table, 'slogan_color', $this->string(20)->comment('Цвет слогана'));

        // Ручное управление популярностью
        $this->addColumnIfNotExists($table, 'is_manual_popular', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Ручная популярность (0/1)'));

        // Запрос цены
        $this->addColumnIfNotExists($table, 'is_price_on_request', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Цена по запросу (0/1)'));

        // Мягкое удаление
        $this->addColumnIfNotExists($table, 'deleted_at', $this->dateTime()->comment('Дата мягкого удаления'));

        // Изменение новинки
        $this->addColumnIfNotExists($table, 'isNew_changed', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Изменение статуса новинки (0/1)'));

        // Фраза выгоды
        $this->addColumnIfNotExists($table, 'benefit_phrase', $this->string(31)->comment('Фраза выгоды'));

        // Прямая ссылка
        $this->addColumnIfNotExists($table, 'is_on_direct_link', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Прямая ссылка (0/1)'));

        // Примечания
        $this->addColumnIfNotExists($table, 'notes', $this->text()->comment('Примечания'));

        // Категории для сравнения и фида
        $this->addColumnIfNotExists($table, 'category_id_for_compare', $this->integer()->comment('ID категории для сравнения'));
        $this->addColumnIfNotExists($table, 'category_id_for_feed', $this->integer()->comment('ID категории для фида'));

        // Аренда
        $this->addColumnIfNotExists($table, 'is_rent', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Аренда (0/1)'));

        // Видео
        $this->addColumnIfNotExists($table, 'desktop_video', $this->string(255)->comment('Видео для десктопа'));
        $this->addColumnIfNotExists($table, 'mobile_video', $this->string(255)->comment('Видео для мобильных'));

        // Описание в Markdown
        $this->addColumnIfNotExists($table, 'md_description', $this->text()->comment('Описание в формате Markdown'));

        // Мультигалерея
        $this->addColumnIfNotExists($table, 'multigallery', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Мультигалерея (0/1)'));

        // Чёрная карта
        $this->addColumnIfNotExists($table, 'is_black_card', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Чёрная карта (0/1)'));

        // Старый товар
        $this->addColumnIfNotExists($table, 'is_old', $this->smallInteger(1)->unsigned()->defaultValue(0)->comment('Старый товар (0/1)'));

        // Обновлённый товар
        $this->addColumnIfNotExists($table, 'updated_product_id', $this->integer()->comment('ID обновлённого товара'));

        // === Индексы для новых полей ===
        $this->createIndex('idx-goods-deleted_at', $table, 'deleted_at');
        $this->createIndex('idx-goods-created', $table, 'created');
        $this->createIndex('idx-goods-updated', $table, 'updated');
        $this->createIndex('idx-goods-is_manual_popular', $table, 'is_manual_popular');
        $this->createIndex('idx-goods-is_price_on_request', $table, 'is_price_on_request');
        $this->createIndex('idx-goods-category_id_for_compare', $table, 'category_id_for_compare');
        $this->createIndex('idx-goods-category_id_for_feed', $table, 'category_id_for_feed');
        $this->createIndex('idx-goods-updated_product_id', $table, 'updated_product_id');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $table = '{{%goods}}';

        // Удаляем в обратном порядке
        $this->dropIndex('idx-goods-updated_product_id', $table);
        $this->dropIndex('idx-goods-category_id_for_feed', $table);
        $this->dropIndex('idx-goods-category_id_for_compare', $table);
        $this->dropIndex('idx-goods-is_price_on_request', $table);
        $this->dropIndex('idx-goods-is_manual_popular', $table);
        $this->dropIndex('idx-goods-updated', $table);
        $this->dropIndex('idx-goods-created', $table);
        $this->dropIndex('idx-goods-deleted_at', $table);

        $this->dropColumnIfExists($table, 'updated_product_id');
        $this->dropColumnIfExists($table, 'is_old');
        $this->dropColumnIfExists($table, 'is_black_card');
        $this->dropColumnIfExists($table, 'multigallery');
        $this->dropColumnIfExists($table, 'md_description');
        $this->dropColumnIfExists($table, 'mobile_video');
        $this->dropColumnIfExists($table, 'desktop_video');
        $this->dropColumnIfExists($table, 'is_rent');
        $this->dropColumnIfExists($table, 'category_id_for_feed');
        $this->dropColumnIfExists($table, 'category_id_for_compare');
        $this->dropColumnIfExists($table, 'notes');
        $this->dropColumnIfExists($table, 'is_on_direct_link');
        $this->dropColumnIfExists($table, 'benefit_phrase');
        $this->dropColumnIfExists($table, 'isNew_changed');
        $this->dropColumnIfExists($table, 'deleted_at');
        $this->dropColumnIfExists($table, 'is_price_on_request');
        $this->dropColumnIfExists($table, 'is_manual_popular');
        $this->dropColumnIfExists($table, 'slogan_color');
        $this->dropColumnIfExists($table, 'font_size');
        $this->dropColumnIfExists($table, 'slogan');
        $this->dropColumnIfExists($table, 'updated');
        $this->dropColumnIfExists($table, 'created');
        $this->dropColumnIfExists($table, 'action_postion');
        $this->dropColumnIfExists($table, 'cat_in_bread_crumbs');
        $this->dropColumnIfExists($table, 'hiden_price');
        $this->dropColumnIfExists($table, 'view_before_pub');
        $this->dropColumnIfExists($table, 'exceptionForXml');
        $this->dropColumnIfExists($table, 'avitoDesc');
        $this->dropColumnIfExists($table, '_isAvitoXml');
        $this->dropColumnIfExists($table, '_forChildren');
        $this->dropColumnIfExists($table, '_leather');
        $this->dropColumnIfExists($table, '_present');
        $this->dropColumnIfExists($table, '_sticker');
        $this->dropColumnIfExists($table, '_actionSize');
    }

    /**
     * Добавляет колонку, если она не существует
     * @param string $table
     * @param string $column
     * @param \yii\db\ColumnSchemaBuilder $type
     */
    protected function addColumnIfNotExists($table, $column, $type)
    {
        if (!$this->db->getTableSchema($table)->getColumn($column)) {
            $this->addColumn($table, $column, $type);
        }
    }

    /**
     * Удаляет колонку, если она существует
     * @param string $table
     * @param string $column
     */
    protected function dropColumnIfExists($table, $column)
    {
        if ($this->db->getTableSchema($table)->getColumn($column)) {
            $this->dropColumn($table, $column);
        }
    }
}
