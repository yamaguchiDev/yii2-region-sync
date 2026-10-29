<?php

use yii\db\Migration;

class m261008_120000_create_tech_params_table extends Migration
{
    public function up()
    {
        if ($this->db->getTableSchema('{{%tech_params}}', true) !== null) {
            return;
        }
        $this->createTable('{{%tech_params}}', [
            'id' => $this->primaryKey(),
            'name' => $this->text(),
            'value' => $this->text(),
            'sort' => $this->integer(),
            'product_id' => $this->string(45)->notNull(),
        ], 'ENGINE=InnoDB');
        $this->createIndex('idx_tech_params_product_id', '{{%tech_params}}', 'product_id');
    }

    public function down()
    {
        $this->dropTable('{{%tech_params}}');
    }
}
