<?php

namespace yamaguchi\regionsync\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yamaguchi\regionsync\components\PriceImporter;
use yamaguchi\regionsync\services\AvailabilitySyncService;
use yamaguchi\regionsync\services\NewProductImportService;
use yamaguchi\regionsync\services\ProductDiffService;

class DashboardController extends Controller
{
    public $layout = '//admin/column2';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [[
                    'allow' => true,
                    'roles' => ['@'],
                    'matchCallback' => static function () {
                        return in_array(Yii::$app->user->identity->role ?? null, ['admin', 'superadmin'], true);
                    },
                ]],
            ],
            'verbs' => ['class' => VerbFilter::class, 'actions' => [
                'import-one' => ['POST'], 'sync-availability' => ['POST'], 'sync-prices' => ['POST'],
            ]],
        ];
    }

    public function actionIndex($tab = 'availability')
    {
        if (!in_array($tab, ['availability', 'prices', 'products'], true)) {
            throw new BadRequestHttpException('Unknown tab.');
        }
        $diff = null;
        $error = null;
        if ($tab === 'products') {
            try {
                $diff = (new ProductDiffService())->compare();
            } catch (\Throwable $exception) {
                Yii::warning('Catalog comparison failed: ' . get_class($exception), __METHOD__);
                $error = 'Не удалось получить список товаров донора. Проверьте подключение и подпись запроса.';
            }
        }

        return $this->render('index', ['tab' => $tab, 'diff' => $diff, 'error' => $error]);
    }

    public function actionImportOne()
    {
        $itemId = Yii::$app->request->post('itemId');
        if (!is_scalar($itemId) || !preg_match('/^[1-9][0-9]*$/D', (string)$itemId) || (int)$itemId < 1) {
            throw new BadRequestHttpException('Invalid itemId.');
        }
        $itemId = (int)$itemId;
        try {
            $result = (new NewProductImportService())->importOne($itemId, false);
            if (empty($result['success'])) {
                throw new \RuntimeException($result['error'] ?? 'Import failed.');
            }
            Yii::$app->session->setFlash('success', 'Импортирован itemId ' . $itemId . ', goods.id ' . $result['productId']);
        } catch (\Throwable $exception) {
            Yii::error('Product import failed for itemId ' . $itemId . ': ' . get_class($exception) . ' ' . $exception->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Импорт не выполнен: ' . $exception->getMessage());
        }

        return $this->redirect(['/regionsync/dashboard/index', 'tab' => 'products']);
    }

    public function actionSyncAvailability()
    {
        $cityId = Yii::$app->request->post('geoCityId');
        if (!is_scalar($cityId) || !preg_match('/^[1-9][0-9]*$/D', (string)$cityId)) {
            throw new BadRequestHttpException('Invalid geoCityId.');
        }
        try {
            $result = (new AvailabilitySyncService($this->module->apiHost, (int)$cityId))->sync();
            Yii::$app->session->setFlash(!empty($result['success']) ? 'success' : 'error',
                !empty($result['success']) ? 'Остатки синхронизированы для geoCityId ' . $cityId
                    : 'Не удалось синхронизировать остатки.');
        } catch (\Throwable $exception) {
            Yii::error('Availability sync failed: ' . get_class($exception) . ' ' . $exception->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Ошибка синхронизации остатков.');
        }
        return $this->redirect(['/regionsync/dashboard/index', 'tab' => 'availability']);
    }

    public function actionSyncPrices()
    {
        try {
            $host = rtrim($this->module->apiHost, '/');
            $result = (new PriceImporter())->importForSite(['id' => 'web', 'host' => $host, 'url' => $host]);
            $success = ($result['status'] ?? '') === 'ok' && empty($result['errors']) && empty($result['error_count']);
            Yii::$app->session->setFlash($success ? 'success' : 'error',
                $success ? 'Цены синхронизированы. Обновлено: ' . (int)($result['updated'] ?? 0)
                    : 'Не удалось синхронизировать цены.');
        } catch (\Throwable $exception) {
            Yii::error('Price sync failed: ' . get_class($exception) . ' ' . $exception->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('error', 'Ошибка синхронизации цен.');
        }
        return $this->redirect(['/regionsync/dashboard/index', 'tab' => 'prices']);
    }
}
