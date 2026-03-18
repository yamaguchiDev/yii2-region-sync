<?php

use yii\db\Migration;

/**
 * Создаёт таблицу new_storage_item — справочник товаров (автономная версия).
 */
class m260318_000002_create_new_storage_item_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%new_storage_item}}', [
            'item_id'           => $this->integer()->notNull()->comment('ID товара в Борбозе'),
            'item_title'        => $this->string(250)->null()->comment('Название товара'),
            'price'             => $this->integer()->null()->comment('Цена'),
            'soon'              => $this->string(250)->null()->comment('Информация о поставке'),
            'netto_weight_item' => $this->float()->null()->comment('Вес нетто, кг'),
            'gross_weight_item' => $this->float()->null()->comment('Вес с коробкой, кг'),
            'npresence_comment' => $this->string(250)->null()->comment('Особенности расчёта наличия'),
        ]);

        $this->addPrimaryKey('pk-new_storage_item', '{{%new_storage_item}}', 'item_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%new_storage_item}}');
    }
}
