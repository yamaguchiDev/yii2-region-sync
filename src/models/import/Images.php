<?php
namespace yamaguchi\regionsync\models\import;

use Yii;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;

/**
 * Модель изображений
 * Таблица: {{%images}} (уже существует)
 */
class Images extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%new_image}}';
    }

    public function rules()
    {
        return [
            [['fileName', 'nodeId'], 'required'],
            [['nodeId'], 'integer'],
            [['fileName'], 'string'],
            [['fileName', 'nodeId'], 'unique', 'targetAttribute' => ['fileName', 'nodeId'], 'message' => 'Комбинация fileName и nodeId должна быть уникальной.'],
        ];
    }

    /**
     * Поиск изображения по узлу
     */
    public static function findByNode($nodeId, $nodeType, $isPng = null)
    {
        $query = static::find()
            ->where(['nodeId' => $nodeId, 'nodeType' => $nodeType]);

        if ($isPng !== null) {
            $query->andWhere(['is_png' => $isPng]);
        }

        return $query->one();
    }

    /**
     * Создание или обновление изображения
     */
    public static function createOrUpdate($data, $productId)
    {
        $fileName = $data['fileName'] ?? '';

        // Ищем существующее фото по имени файла и товару, чтобы не дублировать, но и не перезаписывать другие
        $image = static::findOne(['nodeId' => $productId, 'nodeType' => 'product', 'fileName' => $fileName]);

        if (!$image) {
            $image = new static ();
            $image->nodeId = $productId;
            $image->nodeType = 'product';
        }

        $image->fileName = $fileName;
        $image->alt = $data['alt'] ?? '';
        $image->title = $data['title'] ?? '';
        $image->action = $data['action'] ?? 0;
        $image->position = $data['position'] ?? 0;

        if ($image->validate()) {
            return $image->save();
        }

    }


    /**
     * Сохранение изображения из базы64 данных
     *
     * @param array $fileData Данные файла (из удалённого API)
     * @param int $productId ID товара
     * @param string $uri URI товара для пути сохранения
     * @return bool|array Возвращает данные изображения или ошибку
     */
    public static function saveFromBase64($fileData, $productId, $uri)
    {
        try {
            $fileName = $fileData['filename'];
            $base64Data = $fileData['data'] ?? null;

            if (!$base64Data) {
                Yii::warning("Нет данных для файла: {$fileName}", __METHOD__);
                return false;
            }

            // Декодируем базу64
            $binaryData = base64_decode($base64Data);

            if ($binaryData === false) {
                Yii::error("Ошибка декодирования base64 для файла: {$fileName}", __METHOD__);
                return false;
            }

            // Определяем путь сохранения
            $basePath = Yii::getAlias('@webroot/images/product/' . $uri);

            // Создаём директорию если не существует
            if (!is_dir($basePath)) {
                FileHelper::createDirectory($basePath, 0775, true);
            }

            // Полный путь к файлу
            $filePath = $basePath . '/' . $fileName;

            // Сохраняем файл
            $bytesWritten = file_put_contents($filePath, $binaryData);

            if ($bytesWritten === false) {
                Yii::error("Ошибка записи файла: {$filePath}", __METHOD__);
                return false;
            }

            Yii::info("Файл сохранён: {$filePath} ({$bytesWritten} байт)", __METHOD__);

            // Создаём/обновляем запись в БД
            $image = static::findByNode($productId, 'product');

            if (!$image) {
                $image = new static ();
                $image->nodeId = $productId;
                $image->nodeType = 'product';
            }

            // Определяем параметры из имени файла
            $isPng = strpos(strtolower($fileName), '.png') !== false ? 1 : 0;
            $typeImage = 0; // стандартный тип

            // Заполняем данные
            $image->fileName = $fileName;
            $image->type_image = $typeImage;
            $image->alt = ''; // можно заполнить из метаданных товара
            $image->title = '';
            $image->action = 0;
            $image->position = 0; // можно определить из имени файла или порядка

            if (!$image->save(false)) {
                Yii::error("Ошибка сохранения записи изображения в БД: " . print_r($image->errors, true), __METHOD__);
                return false;
            }

            return [
                'success' => true,
                'imageId' => $image->id,
                'fileName' => $fileName,
                'filePath' => $filePath,
                'bytesWritten' => $bytesWritten,
            ];

        }
        catch (\Throwable $e) {
            Yii::error("Ошибка сохранения изображения: " . $e->getMessage(), __METHOD__);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Массовое сохранение изображений из базы64
     *
     * @param array $files Массив файлов из удалённого API
     * @param int $productId ID товара
     * @param string $uri URI товара
     * @return array Статистика сохранения
     */
    public static function saveMultipleFromBase64($files, $productId, $uri)
    {
        $stats = [
            'total' => count($files),
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($files as $index => $fileData) {
            if (!$fileData['exists']) {
                $stats['failed']++;
                $stats['details'][] = [
                    'filename' => $fileData['filename'],
                    'status' => 'not_exists',
                    'error' => 'Файл не существует на удалённом сервере',
                ];
                continue;
            }

            $result = static::saveFromBase64($fileData, $productId, $uri);

            if ($result && $result['success']) {
                $stats['success']++;
                $stats['details'][] = [
                    'filename' => $fileData['filename'],
                    'status' => 'success',
                    'imageId' => $result['imageId'],
                    'bytes' => $result['bytesWritten'],
                ];
            }
            else {
                $stats['failed']++;
                $stats['details'][] = [
                    'filename' => $fileData['filename'],
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'Неизвестная ошибка',
                ];
            }
        }

        return $stats;
    }
}
