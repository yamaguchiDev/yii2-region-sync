<?php

use yii\db\Migration;

/**
 * Создаёт таблицу new_storage_place_product — остатки товаров по складам (автономная версия).
 */
class m260318_000003_create_new_storage_place_product_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%new_storage_place_product}}', [
            '_id'                 => $this->primaryKey()->comment('Внутренний ID записи'),
            'place_id'            => $this->integer()->notNull()->comment('ID склада'),
            'item_id'             => $this->integer()->notNull()->comment('ID товара в Борбозе'),
            'quantity'            => $this->integer()->notNull()->defaultValue(0)->comment('Количество на складе'),
            '_point_for_sales_id' => $this->integer()->notNull()->defaultValue(0)->comment('ID точки продаж'),
            '_place_type_id'      => $this->integer()->notNull()->defaultValue(0)->comment('ID типа склада'),
            'created'             => $this->dateTime()->null()->comment('Дата последнего обновления'),
        ]);

        $this->createIndex(
            'uq-new_spp-place_item',
            '{{%new_storage_place_product}}',
            ['place_id', 'item_id'],
            true
        );

        $this->createIndex('idx-new_spp-item_id',  '{{%new_storage_place_product}}', 'item_id');
        $this->createIndex('idx-new_spp-place_id', '{{%new_storage_place_product}}', 'place_id');
        $this->createIndex('idx-new_spp-quantity', '{{%new_storage_place_product}}', 'quantity');
    }

    public function safeDown()
    {
        $this->dropTable('{{%new_storage_place_product}}');
    }
}
