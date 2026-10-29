<?php

use yii\db\Migration;

class m261008_120100_create_price_items_sets_table extends Migration
{
    public function up()
    {
        if ($this->db->getTableSchema('{{%price_items_sets}}', true) !== null) {
            return;
        }
        $this->createTable('{{%price_items_sets}}', [
            'id' => $this->primaryKey(),
            'main_item_id' => $this->integer(),
            'item_id' => $this->integer(),
            'price_in_set' => $this->decimal(8, 2),
            'quantity_in_set' => $this->integer(),
            'created_dt' => $this->timestamp()->null(),
            'deleted' => $this->boolean()->defaultValue(0),
        ], 'ENGINE=InnoDB');
        $this->createIndex('idx_price_items_sets_main_item_id', '{{%price_items_sets}}', 'main_item_id');
    }

    public function down()
    {
        $this->dropTable('{{%price_items_sets}}');
    }
}
