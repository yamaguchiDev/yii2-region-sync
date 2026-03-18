<?php

use yii\db\Migration;

/**
 * Создаёт таблицу new_storage_place — склады/точки продаж (автономная версия).
 * Синхронизируется с главного сайта (yamaguchi.ru) через AvailabilitySyncService.
 */
class m260318_000001_create_new_storage_place_table extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%new_storage_place}}', [
            'place_id'             => $this->integer()->notNull()->comment('ID склада в Борбозе'),
            'geo_location_id'      => $this->integer()->null()->comment('ID региона'),
            'geo_location_name'    => $this->string(127)->null()->comment('Название региона'),
            'geo_city_id'          => $this->integer()->null()->comment('ID города по геобазе'),
            'point_for_sales_name' => $this->string(127)->null()->comment('Название точки продаж'),
            'town_city_id'         => $this->integer()->null()->comment('ID главного города региона'),
            'point_for_sales_id'   => $this->integer()->null()->comment('ID точки продаж'),
            'place_name'           => $this->string(127)->null()->comment('Название склада'),
            'type_code'            => $this->string(127)->null()->comment('Тип склада: main, show_room'),
            'closed'               => $this->smallInteger(1)->null()->defaultValue(0)->comment('Закрыт ли склад'),
        ], $tableOptions);

        $this->addPrimaryKey('pk-new_storage_place', '{{%new_storage_place}}', 'place_id');
        $this->createIndex('idx-nsp-geo_city_id',        '{{%new_storage_place}}', 'geo_city_id');
        $this->createIndex('idx-nsp-type_code',          '{{%new_storage_place}}', 'type_code');
        $this->createIndex('idx-nsp-point_for_sales_id', '{{%new_storage_place}}', 'point_for_sales_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%new_storage_place}}');
    }
}
