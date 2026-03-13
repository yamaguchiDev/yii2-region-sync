<?php

namespace app\modules\regions\controllers;

use app\common\behaviors\HmacAuthBehavior;
use app\modules\common\components\Controller;
use app\modules\product\models\Product;
use app\modules\product\models\ProductVariant;
use app\modules\regions\models\RegionSiteUpdate;
use app\modules\storage\models\StorageItem;
use app\modules\storage\models\StoragePlace;
use app\modules\storage\models\StoragePlaceProduct;
use app\modules\storage\modules\city\models\StorageCity;
use yii\db\Query;
use Yii;
use yii\web\Response;


/**
 * site/export-data/get-video-vk?token=Dg9G1t9TBuWYWVnM
 */
class ExportDataController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        return [
            'hmac' => [
                'class' => HmacAuthBehavior::class,
                'secret' => Yii::$app->params['apiSecret'],
            ],
        ];
    }

    /**
     * /regions/export-data/get-prices
     * Получение обновленных цен
     * @param string $token
     */
    public function actionGetPrices()
    {
        Yii::$app->cache->delete('prices_export');

        $data = Yii::$app->cache->getOrSet('prices_export', function () {

            $subQuery = (new \yii\db\Query())
                ->select(['itemId', 'MIN(position) AS minPos'])
                ->from('new_product_variant')
                ->groupBy('itemId');

            return ProductVariant::find()
                ->alias('pv')
                ->select([
                    'pv.id',
                    'pv.productId',
                    'pv.variantId',
                    'pv.itemId',
                    'pv.price',
                    'pv.priceAction',
                    'pv.barcode'
                ])
                ->joinWith(['product g' => function ($q) {
                    $q->andWhere(['g.published' => 1]);
                }])
                ->innerJoin(
                    ['t' => $subQuery],
                    't.itemId = pv.itemId AND t.minPos = pv.position'
                )
                ->orderBy(['pv.itemId' => SORT_ASC])
                ->all();
        });


        Yii::$app->response->format = Response::FORMAT_JSON;

        return $data;
    }

    /**
     * /regions/export-data/refrash-status-update?type=1&token=Dg9G1t9TBuWYWVnM
     * @param string $token
     */

    public function actionRefrashStatusUpdate($type)
    {

        // Поиск всех регионов, где нужно обновить статус
        $model = RegionSiteUpdate::find()->where(['id' => $type])->one();

        if (!$model) {
            return ['status' => 'no data'];
        }

        $model->updated_at = date('Y-m-d H:i:s');

        if ($model->validate()) {
            return $model->save(false);
        };

    }


    private function logError($message)
    {
        Yii::error($message, 'api_files');
    }


    /**
     * /regions/export-data/get-files-for-item-id?itemId=1389
     */

    public function actionGetFilesForItemId($itemId)
    {

        Yii::$app->response->format = Response::FORMAT_JSON;

        $errors = [];

        try {


            $variant = $this->getOneVariantByItemId($itemId);

            if (!$variant) {
                $msg = "Не найден new_product_variant по itemId = {$itemId}";
                $this->logError($msg);
                return ['status' => 'error', 'message' => $msg, 'errors' => [$msg]];
            }

            $productId = $variant['productId'];


            $goods = (new Query())
                ->from('goods')
                ->where(['id' => $productId])
                ->one();

            if (!$goods) {
                $msg = "Не найден goods по productId = {$productId}";
                $this->logError($msg);
                return ['status' => 'error', 'message' => $msg, 'errors' => [$msg]];
            }

            $goodsId = $goods['id'];
            $uri = $goods['uri'];


            $images = (new Query())
                ->from('new_image')
                ->where(['nodeId' => $goodsId])
                ->orderBy(['position' => SORT_ASC])
                ->all();

            if (!$images) {
                $msg = "Нет изображений в new_image для goods.id = {$goodsId}";
                $this->logError($msg);


                return [
                    'status' => 'ok',
                    'uri' => $uri,
                    'files' => [],
                    'errors' => [$msg]
                ];
            }

            $basePath = Yii::getAlias('@webroot/images/product/' . $uri);

            $result = [];

            foreach ($images as $img) {
                $filePath = $basePath . '/' . $img['fileName'];

                if (!file_exists($filePath)) {
                    $msg = "Файл не найден: {$filePath}";
                    $this->logError($msg);
                    $errors[] = $msg;

                    $result[] = [
                        'filename' => $img['fileName'],
                        'exists' => false,
                        'data' => null
                    ];
                    continue;
                }

                try {
                    $result[] = [
                        'filename' => $img['fileName'],
                        'position' => $img['position'] ?? 0,
                        'alt' => $img['alt'] ?? '',
                        'title' => $img['title'] ?? '',
                        'action' => $img['action'] ?? 0,
                        'is_png' => $img['is_png'] ?? 0,
                        'type_image' => $img['type_image'] ?? 0,
                        'exists' => true,
                        'mime' => mime_content_type($filePath),
                        'data' => base64_encode(file_get_contents($filePath))
                    ];
                } catch (\Throwable $e) {
                    $msg = "Ошибка чтения файла {$filePath}: " . $e->getMessage();
                    $this->logError($msg);
                    $errors[] = $msg;

                    $result[] = [
                        'filename' => $img['fileName'],
                        'exists' => false,
                        'data' => null
                    ];
                }
            }

            return [
                'status' => 'ok',
                'itemId' => $itemId,
                'uri' => $uri,
                'files' => $result,
                'errors' => $errors
            ];

        } catch (\Throwable $e) {
            $msg = "Фатальная ошибка: " . $e->getMessage();
            $this->logError($msg);

            return [
                'status' => 'error',
                'message' => 'fatal error',
                'errors' => [$msg]
            ];
        }
    }

    public function getOneVariantByItemId(int $itemId)
    {
        $subQuery = (new \yii\db\Query())
            ->select(['MIN(position) AS minPos'])
            ->from('new_product_variant')
            ->where(['itemId' => $itemId]);

        return ProductVariant::find()
            ->alias('pv')
            ->select([
                'pv.id',
                'pv.productId',
                'pv.variantId',
                'pv.itemId',
                'pv.price',
                'pv.priceAction',
                'pv.barcode'
            ])
            ->joinWith(['product g' => function ($q) {
                $q->andWhere(['g.published' => 1]);
            }])
            ->andWhere(['pv.itemId' => $itemId])
            ->innerJoin(
                ['t' => $subQuery],
                't.minPos = pv.position'
            )
            ->orderBy(['pv.itemId' => SORT_ASC])
            ->one();
    }


    /**
     *
     * regions/export-data/get-item-by-item-id
     * @param $itemId
     * @return void
     */
    public function actionGetItemByItemId($itemId)
    {
        $product = Product::getProducts([
            'itemId' => $itemId,
            'attrForFilter' => true,
            'withImages' => true
        ]);

        Yii::$app->response->format = Response::FORMAT_JSON;
        return $product[0]['products'] ?? null;


    }

    /*
     *  * regions/export-data/get-all-products
     */
    public function actionGetAllProducts()
    {
        $products = Product::getProducts();

        Yii::$app->response->format = Response::FORMAT_JSON;
        return $products[0]['products'] ?? null;

    }

    /**
     * Экспорт данных наличия для регионального сайта.
     *
     * GET /regions/export-data/get-availability?geoCityId=71902
     *
     * Возвращает пакет данных для синхронизации наличия:
     *   - storage_city:           метаданные региона
     *   - storage_place:          склады региона + московский главный склад (place_id = 5514)
     *   - storage_place_product:  остатки товаров на этих складах (quantity > 0)
     *   - storage_item:           справочник товаров (только присутствующих на складах)
     *
     * Авторизация через HmacAuthBehavior (подписанный запрос).
     * Кэш: 1 час (сбрасывается при product/update-quantities).
     *
     * @param int $geoCityId  geo_city_id региона из storage_city (например, 71902 = Новосибирск)
     */
    public function actionGetAvailability(int $geoCityId)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $cacheKey = "availability_export_{$geoCityId}";

        return Yii::$app->cache->getOrSet($cacheKey, function () use ($geoCityId) {
            return $this->buildAvailabilityData($geoCityId);
        }, 3600);
    }

    /**
     * Строит пакет данных наличия для заданного geo_city_id
     */
    private function buildAvailabilityData(int $geoCityId): array
    {
        // 1. Метаданные региона
        $city = StorageCity::find()
            ->select(['id', 'geo_city_id', 'geo_location_id', 'geo_location_name', 'system_name'])
            ->where(['geo_city_id' => $geoCityId])
            ->asArray()
            ->one();

        // 2. Склады региона + московский главный (place_id = 5514) для расчёта deliveryFrom
        $places = StoragePlace::find()
            ->select([
                'place_id', 'geo_location_id', 'geo_location_name', 'geo_city_id',
                'point_for_sales_name', 'town_city_id', 'point_for_sales_id',
                'place_name', 'type_code', 'closed',
            ])
            ->where(['closed' => 0])
            ->andWhere([
                'or',
                ['geo_city_id' => $geoCityId],
                ['place_id'    => StoragePlace::ID_MAIN],
            ])
            ->asArray()
            ->all();

        $placeIds = array_column($places, 'place_id');

        if (empty($placeIds)) {
            return [
                'storage_city'          => $city,
                'storage_place'         => [],
                'storage_place_product' => [],
                'storage_item'          => [],
            ];
        }

        // 3. Остатки по отфильтрованным складам (quantity > 0)
        $placeProducts = StoragePlaceProduct::find()
            ->select(['place_id', 'item_id', 'quantity'])
            ->where(['place_id' => $placeIds])
            ->andWhere(['>', 'quantity', 0])
            ->asArray()
            ->all();

        // 4. Уникальные item_id с остатками > 0
        $itemIds = array_values(array_unique(array_column($placeProducts, 'item_id')));

        // 5. Справочник товаров
        $items = $itemIds
            ? StorageItem::find()
                ->select(['item_id', 'item_title', 'price', 'soon',
                          'netto_weight_item', 'gross_weight_item', 'npresence_comment'])
                ->where(['item_id' => $itemIds])
                ->asArray()
                ->all()
            : [];

        return [
            'storage_city'          => $city,
            'storage_place'         => $places,
            'storage_place_product' => $placeProducts,
            'storage_item'          => $items,
        ];
    }

}
