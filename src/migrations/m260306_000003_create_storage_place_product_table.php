<?php

use yii\db\Migration;

/**
 * Создаёт таблицу storage_place_product — остатки товаров по складам
 * Синхронизируется с главного сайта (yamaguchi.ru)
 */
class m260306_000003_create_storage_place_product_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%storage_place_product}}', [
            '_id'                => $this->primaryKey()->comment('Внутренний ID записи'),
            'place_id'           => $this->integer()->notNull()->comment('ID склада'),
            'item_id'            => $this->integer()->notNull()->comment('ID товара в Борбозе'),
            'quantity'           => $this->integer()->notNull()->defaultValue(0)->comment('Количество на складе'),
            '_point_for_sales_id' => $this->integer()->notNull()->defaultValue(0)->comment('ID точки продаж (служебное)'),
            '_place_type_id'     => $this->integer()->notNull()->defaultValue(0)->comment('ID типа склада (служебное)'),
            'created'            => $this->dateTime()->null()->comment('Дата последнего обновления'),
        ]);

        // Уникальный ключ для upsert по (place_id, item_id)
        $this->createIndex(
            'uq-storage_place_product-place_item',
            '{{%storage_place_product}}',
            ['place_id', 'item_id'],
            true
        );

        $this->createIndex('idx-storage_place_product-item_id', '{{%storage_place_product}}', 'item_id');
        $this->createIndex('idx-storage_place_product-place_id', '{{%storage_place_product}}', 'place_id');
        $this->createIndex('idx-storage_place_product-quantity', '{{%storage_place_product}}', 'quantity');
    }

    public function safeDown()
    {
        $this->dropTable('{{%storage_place_product}}');
    }
}
