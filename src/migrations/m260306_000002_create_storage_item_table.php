<?php

use yii\db\Migration;

/**
 * Создаёт таблицу storage_item — справочник товаров из Борбозы
 * Синхронизируется с главного сайта (yamaguchi.ru)
 */
class m260306_000002_create_storage_item_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%storage_item}}', [
            'item_id'            => $this->integer()->notNull()->comment('ID товара в Борбозе'),
            'item_title'         => $this->string(250)->null()->comment('Название товара в Борбозе'),
            'price'              => $this->integer()->null()->comment('Цена'),
            'soon'               => $this->string(250)->null()->comment('Информация о поставке товара'),
            'netto_weight_item'  => $this->float()->null()->comment('Вес товара нетто, кг'),
            'gross_weight_item'  => $this->float()->null()->comment('Вес товара с коробкой, кг'),
            'npresence_comment'  => $this->string(250)->null()->comment('Особенности расчёта наличия'),
        ]);

        $this->addPrimaryKey('pk-storage_item', '{{%storage_item}}', 'item_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%storage_item}}');
    }
}
