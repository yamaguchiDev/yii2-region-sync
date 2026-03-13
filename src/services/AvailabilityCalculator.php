<?php

namespace yamaguchi\regionsync\services;

use yamaguchi\regionsync\models\availability\AvailabilityResult;
use yamaguchi\regionsync\models\availability\StorageItem;
use yamaguchi\regionsync\models\availability\StoragePlace;
use yamaguchi\regionsync\models\availability\StoragePlaceProduct;
use Yii;

/**
 * Сервис расчёта наличия товара на региональном сайте.
 *
 * Логика портирована из StorageItemAvailability::calculateAvailability()
 * главного сайта (yamaguchi.ru), упрощена до расчёта по региону.
 *
 * Работает полностью автономно, опирается только на локальные таблицы:
 *   storage_place, storage_place_product, storage_item
 *
 * Использование:
 * ```php
 * $calculator = new AvailabilityCalculator();
 * $result = $calculator->calculate($itemId, $geoCityId);
 * echo $result->availability;  // 'main', 'check', 'no', 'preorder', 'discontinued'
 * ```
 */
class AvailabilityCalculator
{
    /** Показывать точное кол-во если от 1 до 2 шт включительно */
    const QUANTITY_SHOW_MIN = 1;
    const QUANTITY_SHOW_MAX = 2;

    /** Длительность кэша, секунд */
    const CACHE_DURATION = 3600;

    /** @var bool Использовать кэш */
    public $useCache = true;

    /**
     * Рассчитать наличие товара для заданного региона
     *
     * @param int|int[] $itemId  ID товара (или массив ID вариантов)
     * @param int       $geoCityId  geo_city_id региона из storage_city
     * @return AvailabilityResult
     */
    public function calculate($itemId, int $geoCityId): AvailabilityResult
    {
        $result = new AvailabilityResult();

        if (!$itemId) {
            return $result;
        }

        $cacheKey = $this->getCacheKey($itemId, $geoCityId);

        if ($this->useCache) {
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 1. Проверяем снятие с производства и предзаказ через goods
        //    Эти данные синхронизируются через существующий ImportController
        if ($this->getIsDiscontinued($itemId)) {
            $result->availability = AvailabilityResult::AVAILABILITY_DISCONTINUED;
            return $this->storeInCache($cacheKey, $result);
        }

        if ($this->getIsPreorder($itemId)) {
            $result->availability = AvailabilityResult::AVAILABILITY_PREORDER;
            return $this->storeInCache($cacheKey, $result);
        }

        // 2. Суммируем остатки по основным складам региона
        $sum = $this->getSumInRegion($itemId, $geoCityId);

        // 3. Суммируем остатки по шоу-рум складам региона
        $sumShowroom = $this->getSumOnShowroom($itemId, $geoCityId);

        // 4. Устанавливаем точное значение, если товара мало (1–2 шт)
        if ($sum >= self::QUANTITY_SHOW_MIN && $sum <= self::QUANTITY_SHOW_MAX) {
            $result->value = $sum;
        }

        // 5. Определяем статус наличия
        if ($sum > 0) {
            $result->availability = AvailabilityResult::AVAILABILITY_ON_MAIN;
        }

        // 6. Если мало товара — статус «уточняйте»
        if ($result->availability === AvailabilityResult::AVAILABILITY_ON_MAIN && $result->value !== null) {
            $result->availability = AvailabilityResult::AVAILABILITY_CHECK;
        }

        // 7. Есть ли шоу-рум / тест-драйв
        if ($sumShowroom > 0) {
            $result->hasTestDrive = true;
        }

        // 8. Проверяем откуда будет доставка (только для не-московских регионов)
        if ($geoCityId !== StoragePlace::ID_MAIN) {
            $result->deliveryFrom = $this->getDeliveryFrom($itemId);
        }

        return $this->storeInCache($cacheKey, $result);
    }

    /**
     * Проверить что товар снят с производства
     * @param int|int[] $itemId
     */
    private function getIsDiscontinued($itemId): bool
    {
        // goods.discontinued синхронизируется через ImportController
        return (bool) Yii::$app->db->createCommand('
            SELECT COUNT(*) FROM {{%goods}}
            WHERE discontinued = 1
            AND id IN (
                SELECT productId FROM {{%new_product_variant}} WHERE itemId IN (:ids)
            )
        ', [':ids' => implode(',', (array)$itemId)])->queryScalar();
    }

    /**
     * Проверить что товар доступен по предзаказу
     * @param int|int[] $itemId
     */
    private function getIsPreorder($itemId): bool
    {
        return (bool) Yii::$app->db->createCommand('
            SELECT COUNT(*) FROM {{%goods}}
            WHERE isPreorder = 1
            AND id IN (
                SELECT productId FROM {{%new_product_variant}} WHERE itemId IN (:ids)
            )
        ', [':ids' => implode(',', (array)$itemId)])->queryScalar();
    }

    /**
     * Суммирует остатки на основных складах города
     * Для Москвы — только московский главный склад (ID_MAIN = 5514)
     * Для регионов — sum quantity WHERE type_code = 'main' AND geo_city_id = $geoCityId
     *
     * @param int|int[] $itemId
     */
    private function getSumInRegion($itemId, int $geoCityId): int
    {
        $query = StoragePlaceProduct::find()
            ->alias('spp')
            ->select(new \yii\db\Expression('COALESCE(SUM(spp.quantity), 0) as total'))
            ->leftJoin(['sp' => 'storage_place'], 'sp.place_id = spp.place_id')
            ->where(['spp.item_id' => $itemId])
            ->andWhere(['>', 'spp.quantity', 0]);

        if ($geoCityId === StoragePlace::ID_MAIN) {
            // Московский регион: только по главному складу
            $query->andWhere(['sp.place_id' => StoragePlace::ID_MAIN]);
        } else {
            // Регион: основные склады этого города
            $query->andWhere([
                'sp.type_code'   => StoragePlace::TYPE_CODE_MAIN,
                'sp.geo_city_id' => $geoCityId,
            ]);
        }

        $result = $query->asArray()->one();
        return (int)($result['total'] ?? 0);
    }

    /**
     * Суммирует остатки по шоу-рум складам города
     * @param int|int[] $itemId
     */
    private function getSumOnShowroom($itemId, int $geoCityId): int
    {
        if ($geoCityId === StoragePlace::ID_MAIN) {
            return 0; // для Москвы шоу-рум считается отдельно (не нужен в регионе)
        }

        $result = StoragePlaceProduct::find()
            ->alias('spp')
            ->select(new \yii\db\Expression('COALESCE(SUM(spp.quantity), 0) as total'))
            ->leftJoin(['sp' => 'storage_place'], 'sp.place_id = spp.place_id')
            ->where([
                'spp.item_id'    => $itemId,
                'sp.type_code'   => StoragePlace::TYPE_CODE_SHOWROOM,
                'sp.geo_city_id' => $geoCityId,
            ])
            ->andWhere(['>', 'spp.quantity', 0])
            ->asArray()
            ->one();

        return (int)($result['total'] ?? 0);
    }

    /**
     * Определяет, откуда будет доставка.
     * Если товар есть на московском главном складе — доставка из Москвы.
     * @param int|int[] $itemId
     */
    private function getDeliveryFrom($itemId): ?string
    {
        $exists = StoragePlaceProduct::find()
            ->where([
                'place_id' => StoragePlace::ID_MAIN,
                'item_id'  => $itemId,
            ])
            ->andWhere(['>', 'quantity', 0])
            ->exists();

        return $exists ? AvailabilityResult::DELIVERY_FROM_MOSCOW : null;
    }

    // =========================================================================
    // Кэш-методы
    // =========================================================================

    private function getCacheKey($itemId, int $geoCityId): string
    {
        $ids = implode(',', (array)$itemId);
        return "availability:{$ids}:{$geoCityId}";
    }

    private function getFromCache(string $key): ?AvailabilityResult
    {
        $cache = Yii::$app->cache ?? null;
        if (!$cache) {
            return null;
        }
        $data = $cache->get($key);
        if (!$data || !is_array($data)) {
            return null;
        }

        $result = new AvailabilityResult();
        $result->availability  = $data['availability']  ?? AvailabilityResult::AVAILABILITY_NO;
        $result->value         = $data['value']         ?? null;
        $result->hasTestDrive  = $data['hasTestDrive']  ?? false;
        $result->deliveryFrom  = $data['deliveryFrom']  ?? null;
        return $result;
    }

    private function storeInCache(string $key, AvailabilityResult $result): AvailabilityResult
    {
        $cache = Yii::$app->cache ?? null;
        if ($cache && $this->useCache) {
            $cache->set($key, $result->toArray(), self::CACHE_DURATION);
        }
        return $result;
    }

    /**
     * Сбросить кэш наличия для конкретного товара
     * @param int|int[] $itemId
     */
    public function clearCache($itemId, int $geoCityId): void
    {
        $cache = Yii::$app->cache ?? null;
        if ($cache) {
            $cache->delete($this->getCacheKey($itemId, $geoCityId));
        }
    }
}
