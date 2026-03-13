<?php

use yii\db\Migration;

/**
 * Создаёт таблицу storage_place — склады/точки продаж
 * Синхронизируется с главного сайта (yamaguchi.ru)
 */
class m260306_000001_create_storage_place_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%storage_place}}', [
            'place_id'           => $this->integer()->notNull()->comment('ID склада в Борбозе'),
            'geo_location_id'    => $this->integer()->null()->comment('ID региона'),
            'geo_location_name'  => $this->string(127)->null()->comment('Название региона'),
            'geo_city_id'        => $this->integer()->null()->comment('ID города-региона по геобазе'),
            'point_for_sales_name' => $this->string(127)->null()->comment('Название точки продаж'),
            'town_city_id'       => $this->integer()->null()->comment('ID главного города региона по геобазе'),
            'point_for_sales_id' => $this->integer()->null()->comment('ID точки продаж'),
            'place_name'         => $this->string(127)->null()->comment('Название склада'),
            'type_code'          => $this->string(127)->null()->comment('Тип склада: main, show_room'),
            'closed'             => $this->smallInteger(1)->null()->defaultValue(0)->comment('Закрыт ли склад'),
        ]);

        $this->addPrimaryKey('pk-storage_place', '{{%storage_place}}', 'place_id');

        $this->createIndex('idx-storage_place-geo_city_id', '{{%storage_place}}', 'geo_city_id');
        $this->createIndex('idx-storage_place-type_code', '{{%storage_place}}', 'type_code');
        $this->createIndex('idx-storage_place-point_for_sales_id', '{{%storage_place}}', 'point_for_sales_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%storage_place}}');
    }
}
