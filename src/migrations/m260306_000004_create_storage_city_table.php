<?php

use yii\db\Migration;

/**
 * Создаёт таблицу storage_city — справочник регионов
 * Синхронизируется с главного сайта (yamaguchi.ru)
 */
class m260306_000004_create_storage_city_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%storage_city}}', [
            'id'                  => $this->primaryKey()->comment('Внутренний ID записи'),
            'geo_city_id'         => $this->integer()->notNull()->comment('ID города по геобазе (уникальный)'),
            'geo_location_id'     => $this->integer()->null()->comment('ID региона'),
            'geo_location_name'   => $this->string(100)->null()->comment('Название города/региона'),
            'system_name'         => $this->string(100)->null()->comment('Системное название города (напр. moscow, spb)'),
            'products_updated_at' => $this->dateTime()->null()->comment('Последняя синхронизация остатков'),
            'places_updated_at'   => $this->dateTime()->null()->comment('Последняя синхронизация складов'),
        ]);

        $this->createIndex('uq-storage_city-geo_city_id', '{{%storage_city}}', 'geo_city_id', true);
    }

    public function safeDown()
    {
        $this->dropTable('{{%storage_city}}');
    }
}
