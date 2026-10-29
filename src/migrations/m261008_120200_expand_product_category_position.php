<?php

use yii\db\Migration;

class m261008_120200_expand_product_category_position extends Migration
{
    public function up()
    {
        $table = $this->db->getTableSchema('{{%product_category}}', true);
        if ($table === null || !isset($table->columns['position'])) {
            throw new \RuntimeException('product_category.position is missing.');
        }
        if (stripos($table->columns['position']->dbType, 'tinyint') !== false) {
            $this->alterColumn('{{%product_category}}', 'position', $this->integer()->notNull()->defaultValue(0));
        }
    }

    public function down()
    {
        // A rollback to tinyint could truncate valid position values greater than 127.
        return false;
    }
}
