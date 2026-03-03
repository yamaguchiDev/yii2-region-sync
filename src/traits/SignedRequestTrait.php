<?php

namespace yamaguchi\regionsync\traits;

use Yii;

/**
 * Trait для работы с подписанными запросами
 */
trait SignedRequestTrait
{
    private function signedRequest(string $endpoint, string $path)
    {
        // Берем apiToken из настроек модуля, а не из глобальных params
        $secret = Yii::$app->getModule('regionsync')->apiToken;
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '/' . $path, $secret);

        $separator = strpos($endpoint, '?') === false ? '?' : '&';
        
        return $endpoint . $separator . 'timestamp=' . $timestamp . '&signature=' . $signature;
    }
}
