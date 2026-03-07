<?php

namespace yamaguchi\regionsync\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yamaguchi\regionsync\services\AvailabilityCalculator;
use yamaguchi\regionsync\traits\SignedRequestTrait;

/**
 * HTTP API для получения наличия товаров на региональном сайте.
 *
 * Endpoints:
 *   GET /regionsync/availability/get?itemId=123&token=TOKEN
 *   GET /regionsync/availability/batch?itemIds[]=123&itemIds[]=456&token=TOKEN
 *
 * Использование в JavaScript:
 * ```js
 * fetch('/regionsync/availability/get?itemId=1234&token=TOKEN')
 *   .then(r => r.json())
 *   .then(data => console.log(data));
 * ```
 */
class AvailabilityController extends Controller
{
    use SignedRequestTrait;

    /** @var bool Yii2: не запускаем CSRF-проверку для API */
    public $enableCsrfValidation = false;

    /**
     * Получить наличие одного товара
     * GET /regionsync/availability/get?itemId=123&token=TOKEN
     */
    public function actionGet($itemId, $token = null): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $module = Yii::$app->getModule('regionsync');

        // Авторизация по токену (опционально — можно убрать если эндпоинт закрытый)
        if ($token !== null && $token !== $module->apiToken) {
            Yii::$app->response->statusCode = 403;
            return ['success' => false, 'message' => 'Invalid token'];
        }

        if (!$module->geoCityId) {
            Yii::$app->response->statusCode = 500;
            return ['success' => false, 'message' => 'geoCityId not configured'];
        }

        $calculator = new AvailabilityCalculator();
        $result     = $calculator->calculate((int)$itemId, (int)$module->geoCityId);

        return array_merge(
            ['success' => true, 'itemId' => (int)$itemId],
            $result->toArray(),
            ['title' => $result->getTitle()]
        );
    }

    /**
     * Получить наличие нескольких товаров за один запрос
     * GET /regionsync/availability/batch?itemIds[]=123&itemIds[]=456&token=TOKEN
     * POST /regionsync/availability/batch  body: itemIds[]=123&itemIds[]=456
     */
    public function actionBatch($token = null): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $module = Yii::$app->getModule('regionsync');

        if ($token !== null && $token !== $module->apiToken) {
            Yii::$app->response->statusCode = 403;
            return ['success' => false, 'message' => 'Invalid token'];
        }

        if (!$module->geoCityId) {
            Yii::$app->response->statusCode = 500;
            return ['success' => false, 'message' => 'geoCityId not configured'];
        }

        $request = Yii::$app->request;
        $itemIds = $request->get('itemIds', $request->post('itemIds', []));

        if (!is_array($itemIds) || empty($itemIds)) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'message' => 'itemIds required'];
        }

        $calculator = new AvailabilityCalculator();
        $geoCityId  = (int)$module->geoCityId;
        $results    = [];

        foreach ($itemIds as $itemId) {
            $itemId  = (int)$itemId;
            $result  = $calculator->calculate($itemId, $geoCityId);
            $results[$itemId] = array_merge(
                $result->toArray(),
                ['title' => $result->getTitle()]
            );
        }

        return ['success' => true, 'items' => $results];
    }
}
