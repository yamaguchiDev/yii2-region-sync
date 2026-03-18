<?php

use yii\db\Migration;

/**
 * Создаёт таблицу new_storage_city — справочник регионов (автономная версия).
 */
class m260318_000004_create_new_storage_city_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%new_storage_city}}', [
            'id'                  => $this->primaryKey()->comment('Внутренний ID'),
            'geo_city_id'         => $this->integer()->notNull()->comment('ID города по геобазе (уникальный)'),
            'geo_location_id'     => $this->integer()->null()->comment('ID региона'),
            'geo_location_name'   => $this->string(100)->null()->comment('Название города/региона'),
            'system_name'         => $this->string(100)->null()->comment('Системное название (moscow, spb...)'),
            'products_updated_at' => $this->dateTime()->null()->comment('Последняя синхронизация остатков'),
            'places_updated_at'   => $this->dateTime()->null()->comment('Последняя синхронизация складов'),
        ]);

        $this->createIndex('uq-new_storage_city-geo_city_id', '{{%new_storage_city}}', 'geo_city_id', true);
    }

    public function safeDown()
    {
        $this->dropTable('{{%new_storage_city}}');
    }
}
