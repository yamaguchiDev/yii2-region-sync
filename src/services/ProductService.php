<?php

namespace yamaguchi\regionsync\services;

use yamaguchi\regionsync\models\import\Category;
use Yii;

use yamaguchi\regionsync\models\import\Goods;
use yamaguchi\regionsync\models\import\Images;
use yamaguchi\regionsync\models\import\ProductCategories;
use yamaguchi\regionsync\models\import\ProductAttributes;
use yamaguchi\regionsync\models\import\Variants;
use yamaguchi\regionsync\models\import\Urls;

use yii\db\Exception;

/**
 * Сервис для обработки товаров
 * Работает с существующими таблицами
 */
class ProductService
{


    /**
     * Импорт/обновление товара из данных
     *
     * @param array $productData Данные товара
     * @return array Результат операции
     */
    public function importProduct($productData)
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            // === ШАГ 1: Работа с основным товаром ===
            $itemId = $productData['variant']['itemId'] ?? null; // 267
            if (!$itemId && isset($productData['variants']['rent']['variants']) && is_array($productData['variants']['rent']['variants'])) {
                $firstRentVariant = reset($productData['variants']['rent']['variants']);
                if (isset($firstRentVariant['itemId'])) {
                    $itemId = $firstRentVariant['itemId'];
                }
            }

            // Проверяем существование товара по ключевому идентификатору
            $product = Goods::findByItemId($itemId);

            $isNew = false;
            // Если товар не найден по itemId, ищем его по URI, чтобы избежать дублирования
            if (!$product && isset($productData['uri']) && $productData['uri'] !== '') {
                $product = Goods::findOne(['uri' => $productData['uri']]);
            }

            if (!$product) {
                $product = new Goods();
                $isNew = true;
            }

            // Сохраняем товар. Валидация вызовется внутри saveProduct
            if (!$product->saveProduct($productData)) {
                // Если сохранение не прошло из-за ошибки уникальности URI
                if ($product->hasErrors('uri') && !empty($productData['uri'])) {
                    $existingByUri = Goods::findOne(['uri' => $productData['uri']]);
                    if ($existingByUri) {
                        $product = $existingByUri;
                        $isNew = false;
                        // Пробуем обновить найденный по URI товар
                        if (!$product->saveProduct($productData)) {
                            Yii::warning("Обновление товара по URI не удалось: " . json_encode($product->errors), __METHOD__);
                        }
                    }
                    elseif (!$product->id) {
                        throw new Exception("Не удалось сохранить новый товар (ошибка URI): " . json_encode($product->errors));
                    }
                    else {
                        Yii::warning("Не удалось обновить товар (ошибка URI): " . json_encode($product->errors), __METHOD__);
                    }
                }
                elseif (!$product->id) {
                    // Если это новый товар и он не сохранился - прерываем транзакцию
                    throw new Exception("Не удалось сохранить новый товар: " . json_encode($product->errors));
                }
                else {
                    // Если это существующий товар и обновления не прошли валидацию, логируем и идем дальше сохранять варианты
                    Yii::warning("Не удалось обновить основные поля товара: " . json_encode($product->errors), __METHOD__);
                }
            }

            $productId = $product->id;


            $this->processVariants($productData, $productId);


            // === ШАГ 3: Обработка категорий ===
            $this->processCategories($productData, $productId);


            // === ШАГ 6: Обработка URL ===
            $this->processUrl($productData, $productId);


            // === ШАГ 2: Обработка изображений ===
            $this->processImages($productData, $productId);

            // === ШАГ 4: Обработка атрибутов ===
            $this->processAttributes($productData, $productId);

            // Коммит транзакции
            $transaction->commit();

            return [
                'success' => true,
                'productId' => $productId,
                'isNew' => $isNew,
                'message' => $isNew ? 'Товар создан' : 'Товар обновлён',
            ];

        }
        catch (Exception $e) {
            $transaction->rollBack();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить товар по URI (Yii2)
     */
    public function getProductByUri($uri)
    {
        return \Yii::$app->db->createCommand('SELECT * FROM goods WHERE uri = :uri')
            ->bindValue(':uri', $uri)
            ->queryOne();
    }

    /**
     * @var RemoteImageService Сервис для работы с удалённым сервером
     */
    private $remoteImageService;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $module = Yii::$app->getModule('regionsync');
        $baseUrl = $module ? $module->apiHost : (Yii::$app->params['mainSiteUrl'] ?? 'https://www.yamaguchi.ru');

        $this->remoteImageService = new RemoteImageService([
            'baseUrl' => $baseUrl,
            'timeout' => 30,
        ]);
    }

    /**
     * Обработка изображений товара
     *
     * Приоритет:
     * 1. Если есть данные изображений в $productData - используем их
     * 2. Если есть itemId и включена опция загрузки - загружаем с удалённого сервера
     * 3. Если ничего нет - пропускаем
     */
    private function processImages($productData, $productId)
    {
        $downloadFromRemote = Yii::$app->params['downloadImagesFromRemote'] ?? true;
        $itemId = $productData['variant']['itemId'] ?? null;

        // === СПОСОБ 1: Данные изображений в самом товаре (массив $productData['images']) ===
        if (isset($productData['images']) && is_array($productData['images'])) {
            Yii::info("Обработка массива изображений из данных товара", __METHOD__);

            foreach ($productData['images'] as $imageData) {
                Images::createOrUpdate($imageData, $productId);
            }
        }
        elseif (isset($productData['image'])) {
            // Оставляем поддержку старого формата (одно изображение)
            Yii::info("Обработка одного изображения из данных товара", __METHOD__);
            Images::createOrUpdate($productData['image'], $productId);
        }

        // === СПОСОБ 2: Загрузка с удалённого сервера по itemId ===
        if ($downloadFromRemote && $itemId) {
            Yii::info("Попытка загрузки изображений с удалённого сервера по itemId={$itemId}", __METHOD__);

            $result = $this->remoteImageService->downloadAndSaveImages(
                $itemId,
                $productId,
                $productData['uri'] ?? null
            );

            if ($result['success']) {
                Yii::info("Изображения успешно загружены: " .
                    $result['stats']['success'] . " из " . $result['stats']['total'], __METHOD__);
            }
            else {
                Yii::warning("Ошибка загрузки изображений: " . $result['error'], __METHOD__);
            }

            return;
        }

        // === СПОСОБ 3: Нет данных об изображениях ===
        Yii::info("Нет данных об изображениях для товара ID={$productId}", __METHOD__);
    }

    /**
     * Обработка атрибутов
     */
    private function processAttributes($data, $productId)
    {
        $data['attrs'] = $data['attrForFilter'];
        if (!isset($data['attrs']) || !is_array($data['attrs'])) {
            return;
        }

        foreach ($data['attrs'] as $attrData) {

            // Ищем имя и заголовок (title) атрибута
            // Данные об атрибуте могут лежать либо на верхнем уровне $attrData, либо внутри ключа 'attr' или 'attribute'
            $attrInfo = $attrData['attr'] ?? $attrData['attribute'] ?? $attrData;

            $attributeTitle = $attrInfo['title'] ?? null;
            $attributeName = $attrInfo['name'] ?? null;

            $localAttributeId = null;

            if ($attributeName || $attributeTitle) {
                $localAttribute = null;

                // Ищем атрибут в базе по системному имени
                if ($attributeName) {
                    $localAttribute = \app\modules\attribute\models\Attribute::findOne(['name' => $attributeName]);
                }

                // Если не нашли по системному имени, пробуем найти по названию (title)
                if (!$localAttribute && $attributeTitle) {
                    $localAttribute = \app\modules\attribute\models\Attribute::findOne(['title' => $attributeTitle]);
                }

                if ($localAttribute) {
                    $localAttributeId = $localAttribute->id;
                }
                else {
                    // Атрибута нет в базе - создаем его!
                    $newAttribute = new \app\modules\attribute\models\Attribute();
                    $newAttribute->title = $attributeTitle ?: 'Новый атрибут';
                    // Если системного имени нет, генерируем транслитом из title
                    $newAttribute->name = $attributeName ?: \yii\helpers\Inflector::slug($newAttribute->title);

                    if ($newAttribute->save()) {
                        $localAttributeId = $newAttribute->id;
                        Yii::info("Создан новый атрибут: {$newAttribute->title} (ID: {$localAttributeId})", __METHOD__);
                    }
                    else {
                        Yii::warning("Не удалось создать атрибут {$newAttribute->title}: " . json_encode($newAttribute->errors), __METHOD__);
                    }
                }
            }

            // В качестве отката (fallback), если ID так и не определен
            if (!$localAttributeId && isset($attrData['attribute_id'])) {
                $localAttributeId = $attrData['attribute_id'];
            }

            if ($localAttributeId) {
                $attributeData = [
                    'attribute_id' => $localAttributeId,
                    'value' => $attrData['value'] ?? null,
                ];

                ProductAttributes::createOrUpdate($attributeData, $productId);
            }
        }
    }

    /**
     * Обработка вариантов
     */
    private function processVariants($data, $productId)
    {

        if (!isset($data['variants']) || !is_array($data['variants'])) {
            return;
        }

        if (isset($data['variant']) && is_array($data['variant'])) {
            Variants::createOrUpdate($data['variant'], $productId);
        }

    }

    /**
     * Обработка URL
     */
    private function processUrl($data, $productId)
    {
        if (isset($data['url'])) {
            Urls::createOrUpdate($data['url'], $productId);
        }
    }

    /**
     * Массовый импорт товаров
     */
    public function importProductsBatch($productsData)
    {
        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($productsData as $productId => $productData) {
            $result = $this->importProduct($productData);

            if ($result['success']) {
                $successCount++;
            }
            else {
                $errorCount++;
            }

            $results[$productId] = $result;
        }

        return [
            'total' => count($productsData),
            'success' => $successCount,
            'errors' => $errorCount,
            'details' => $results,
        ];
    }

    /**
     * Обработка категорий товара
     */
    private function processCategories($data, $productId)
    {
        if (!isset($data['categories']) || !is_array($data['categories'])) {
            return;
        }

        foreach ($data['categories'] as $categoryData) {
            try {
                // === ШАГ 1: Проверка и создание категории ===
                $category = $this->processCategory($categoryData);

                if (!$category) {
                    Yii::warning("Не удалось создать/найти категорию, пропускаем", __METHOD__);
                    continue;
                }

                // === ШАГ 2: Проверка и создание привязки товара к категории ===
                $this->processCategoryLink($categoryData, $productId, $category->id);

            }
            catch (\Throwable $e) {
                Yii::error("Ошибка обработки категории: " . $e->getMessage(), __METHOD__);
                continue;
            }
        }
    }

    /**
     * Обработка одной категории
     */
    private function processCategory($categoryData)
    {
        // Данные категории
        $categoryInfo = $categoryData['category'] ?? [];

        if (empty($categoryInfo) || empty($categoryInfo['name'])) {
            Yii::warning("Нет данных категории или имени", __METHOD__);
            return null;
        }

        // === ШАГ 1: Проверка существования категории по имени ===
        $category = Category::findByName($categoryInfo['name']);

        $isNewCategory = false;
        if (!$category) {
            // Создаём новую категорию
            $category = Category::findOrCreate($categoryInfo);
            $isNewCategory = true;
        }
        else {
            // Обновляем существующую категорию
            $category->updateData($categoryInfo);
            Yii::info("Обновлена существующая категория: '{$categoryInfo['name']}' (ID={$category->id})", __METHOD__);
        }

        if (!$category) {
            throw new \RuntimeException("Не удалось создать/найти категорию: {$categoryInfo['name']}");
        }

        // === ШАГ 2: Проверка и создание изображения категории (если есть) ===
        if (isset($categoryInfo['image'])) {
            $this->processCategoryImage($categoryInfo['image'], $category->id);
        }

        // === ШАГ 3: Проверка и создание URL категории ===
        if (isset($categoryInfo['url'])) {
            $url = Urls::findOrCreate($categoryInfo['url']);

            if ($url) {
                // Обновляем параметр категории в URL, если нужно
                if ($url->param != $category->id) {
                    $url->param = $category->id;
                    $url->save(false);
                    Yii::info("Обновлён параметр категории в URL (ID={$url->id})", __METHOD__);
                }
            }
        }

        return $category;
    }

    /**
     * Обработка привязки товара к категории
     */
    private function processCategoryLink($categoryData, $productId, $categoryId)
    {
        // Данные для привязки
        $linkData = [
            'position' => $categoryData['position'] ?? 0,
            'sorting_by_sales' => $categoryData['sorting_by_sales'] ?? 0,
            'category_position' => $categoryData['category_position'] ?? 0,
            'special_sort_position_1' => $categoryData['special_sort_position_1'] ?? null,
        ];

        // Создаём/обновляем связь
        $link = ProductCategories::findOrCreate($productId, $categoryId, $linkData);

        if (!$link) {
            throw new \RuntimeException("Не удалось создать связь товара (ID={$productId}) с категорией (ID={$categoryId})");
        }

        return $link;
    }
    /**
     * Обработка изображения категории
     */
    private function processCategoryImage($imageData, $categoryId)
    {
        $image = Images::findByNode($categoryId, 'category');

        if (!$image) {
            $image = new Images();
            $image->nodeId = $categoryId;
            $image->nodeType = 'category';
        }

        $image->fileName = $imageData['fileName'] ?? '';
        $image->alt = $imageData['alt'] ?? '';
        $image->title = $imageData['title'] ?? '';
        $image->action = $imageData['action'] ?? 0;
        $image->position = $imageData['position'] ?? 0;


        if ($image->validate()) {
            if ($image->save()) {
                Yii::info("Изображение категории (ID={$categoryId}) сохранено", __METHOD__);
            }
            else {
                Yii::warning("Ошибка сохранения изображения категории", __METHOD__);
            }
        }

    }


}
