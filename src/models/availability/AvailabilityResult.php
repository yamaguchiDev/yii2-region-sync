<?php

namespace yamaguchi\regionsync\models\availability;

/**
 * DTO — результат расчёта наличия товара
 */
class AvailabilityResult
{
    /** Полноценное наличие на складе */
    const AVAILABILITY_ON_MAIN = 'main';
    /** Наличие в шоу-руме */
    const AVAILABILITY_ON_SHOWROOM = 'showroom';
    /** Уточняйте по телефону (мало товара) */
    const AVAILABILITY_CHECK = 'check';
    /** Нет в наличии */
    const AVAILABILITY_NO = 'no';
    /** Предзаказ */
    const AVAILABILITY_PREORDER = 'preorder';
    /** Снят с производства */
    const AVAILABILITY_DISCONTINUED = 'discontinued';

    /** Доставка из региона */
    const DELIVERY_FROM_REGION = 'region';
    /** Доставка из Москвы */
    const DELIVERY_FROM_MOSCOW = 'moscow';

    /** @var string Статус наличия */
    public $availability = self::AVAILABILITY_NO;

    /** @var int|null Количество (если 1–2 шт — показываем, иначе null) */
    public $value;

    /** @var bool Есть ли тест-драйв/шоу-рум */
    public $hasTestDrive = false;

    /** @var string|null Откуда доставка: 'moscow' или null */
    public $deliveryFrom;

    public function toArray(): array
    {
        return [
            'availability'  => $this->availability,
            'value'         => $this->value,
            'hasTestDrive'  => $this->hasTestDrive,
            'deliveryFrom'  => $this->deliveryFrom,
        ];
    }

    /**
     * Человекочитаемое название статуса
     */
    public function getTitle(): string
    {
        switch ($this->availability) {
            case self::AVAILABILITY_DISCONTINUED: return 'Снят с производства';
            case self::AVAILABILITY_PREORDER:     return 'Предзаказ';
        }
        
        return $this->isAvailable() ? 'Есть в наличии' : 'Нет в наличии';
    }

    /**
     * Можно ли купить товар прямо сейчас?
     */
    public function isAvailable(): bool
    {
        return in_array($this->availability, [
            self::AVAILABILITY_ON_MAIN,
            self::AVAILABILITY_ON_SHOWROOM,
            self::AVAILABILITY_CHECK
        ]);
    }
}
