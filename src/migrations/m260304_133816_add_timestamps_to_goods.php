<?php

use yii\db\Migration;

/**
 * Class m260304_133816_add_timestamps_to_goods
 */
class m260304_133816_add_timestamps_to_goods extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->addColumn('{{%goods}}', 'created', $this->dateTime()->after('action_postion'));
        $this->addColumn('{{%goods}}', 'updated', $this->dateTime()->after('created'));
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->dropColumn('{{%goods}}', 'updated');
        $this->dropColumn('{{%goods}}', 'created');
    }
}
