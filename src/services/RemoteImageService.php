<?php

namespace yamaguchi\regionsync\services;

use Yii;
use yii\httpclient\Client;
use yamaguchi\regionsync\traits\SignedRequestTrait;
use yamaguchi\regionsync\models\import\Images;

/**
 * Сервис для загрузки изображений с удалённого сервера
 * Использует подпись запросов для безопасности
 */
class RemoteImageService
{
    use SignedRequestTrait;

    /**
     * @var string Базовый URL главного сайта
     */
    private $baseUrl = 'https://www.yamaguchi.ru';

    /**
     * @var int Таймаут запроса в секундах
     */
    private $timeout = 30;

    /**
     * @var Client HTTP клиент
     */
    private $httpClient;

    /**
     * Конструктор
     *
     * @param array $config Конфигурация
     */
    public function __construct($config = [])
    {
        if (isset($config['baseUrl'])) {
            $this->baseUrl = $config['baseUrl'];
        }

        if (isset($config['timeout'])) {
            $this->timeout = $config['timeout'];
        }

        $this->httpClient = new Client([
            'baseUrl' => $this->baseUrl,
            'requestConfig' => [
                'format' => Client::FORMAT_JSON,
                'options' => [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ],
            ],
            'responseConfig' => [
                'format' => Client::FORMAT_JSON,
            ],
        ]);
    }

    /**
     * Получение изображений по itemId с удалённого сервера
     *
     * @param int $itemId ID варианта товара
     * @return array|bool Данные изображений или ошибка
     */
    public function getImagesByItemId($itemId)
    {
        try {
            $path = 'regions/export-data/get-files-for-item-id';
            $endpoint = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/') . '?itemId=' . $itemId;

            // Формируем подписанный запрос
            $signedUrl = $this->signedRequest($endpoint, $path);

            // Выполняем запрос
            $response = $this->httpClient->createRequest()
                ->setMethod('GET')
                ->setUrl($signedUrl)
                ->setOptions([
                    'timeout' => $this->timeout,
                ])
                ->send();

            // Проверка статуса ответа
            if (!$response->isOk) {
                $error = "Ошибка запроса к удалённому серверу: HTTP {$response->statusCode}";
                Yii::warning($error, __METHOD__);
                return ['success' => false, 'error' => $error];
            }

            $data = $response->data;

            // Проверка статуса в ответе
            if (!isset($data['status']) || $data['status'] !== 'ok') {
                $error = $data['message'] ?? 'Неизвестная ошибка удалённого сервера';
                Yii::warning("Ошибка удалённого сервера: {$error}", __METHOD__);
                return ['success' => false, 'error' => $error];
            }

            return [
                'success' => true,
                'uri' => $data['uri'] ?? null,
                'files' => $data['files'] ?? [],
                'errors' => $data['errors'] ?? [],
            ];

        } catch (\Throwable $e) {
            $error = "Исключение при запросе к удалённому серверу: " . $e->getMessage();
            Yii::error($error, __METHOD__);
            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Загрузка и сохранение изображений по itemId
     *
     * @param int $itemId ID варианта товара
     * @param int $productId ID товара локально
     * @param string|null $uri URI товара
     * @return array Результат операции
     */
    public function downloadAndSaveImages($itemId, $productId, $uri = null)
    {
        try {
            // Получаем данные с удалённого сервера
            $result = $this->getImagesByItemId($itemId);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'],
                ];
            }

            $uri = $uri ?? $result['uri'];

            if (!$uri) {
                return [
                    'success' => false,
                    'error' => 'Не указан и не получен URI товара',
                ];
            }

            // Сохраняем изображения
            $stats = Images::saveMultipleFromBase64($result['files'], $productId, $uri);

            return [
                'success' => true,
                'stats' => $stats,
                'uri' => $uri,
            ];

        } catch (\Throwable $e) {
            $error = "Ошибка загрузки изображений: " . $e->getMessage();
            Yii::error($error, __METHOD__);
            return [
                'success' => false,
                'error' => $error,
            ];
        }
    }

    public function setBaseUrl($url)
    {
        $this->baseUrl = $url;
        $this->httpClient->baseUrl = $url;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }
}
