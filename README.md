# Yii2 Region Sync

Модуль синхронизирует цены, наличие и полный snapshot товара с основного сайта Yamaguchi.

## Требования

- PHP 7.2+ (основная проверяемая версия проекта: PHP 8.1)
- Yii 2.0.13+
- `ext-curl`, `ext-fileinfo`, `ext-json`
- доступные для записи `@runtime` и `@webroot/images/product`, `@webroot/video`

## Подключение

```php
'modules' => [
    'regionsync' => [
        'class' => yamaguchi\regionsync\RegionSyncModule::class,
        'apiHost' => 'https://www.yamaguchi.ru',
        'apiToken' => getenv('REGION_SYNC_TOKEN'),
        'apiSecret' => getenv('REGION_SYNC_SECRET'),
        'geoCityId' => 71902,
    ],
],
```

Токены и секреты нельзя хранить в репозитории или передавать в логах.

Миграции наличия:

```bash
php yii migrate --migrationPath=@vendor/yamaguchi/yii2-region-sync/src/migrations --interactive=0
```

## Товары

Админ-страница регионального сайта: `/regionsync/dashboard/index` (для локального
OpenServer с корнем домена вместо webroot: `/web/regionsync/dashboard/index`).
Доступ только администратору; вкладки «Синхронизация наличия», «Синхронизация цен»
и «Импорт товаров» запускают существующие сервисы через POST с CSRF.
Вкладка импорта сравнивает `itemId` только опубликованных товаров донора с
`new_product_variant.position = 0` с основными вариантами региона. Если один
`itemId` встречается дважды, он выводится как конфликт и не импортируется.
Проверка без записи: `php yii regionsync/product/diff`.

Ограничения пилотной версии: импорт использует snapshot API с HMAC, а не Bearer
Agent API. В первом релизе `NewProductImportService` явно отключает категории и
атрибуты через `importCategories=false`, `importAttributes=false`. Пропускаются
`category`, URL категорий, `product_category`, `attribute`, `attribute_group`,
`product_attribute`, а также ID категорий в `goods` (`cat_in_bread_crumbs`,
`category_id_for_compare`, `category_id_for_feed`). Пропуск не удаляет локальные
записи и связи. `tech_params` импортируются; категории нового товара назначаются
вручную. Старые вызовы `FullProductImporter::import()` без опций по-прежнему
импортируют категории и атрибуты. Старый сервис
цен обновляет все варианты по itemId (не только position=0) и может изменять
published. Для синхронизации только основных вариантов нужен отдельный сервис.

Новый товар можно безопасно предварительно проверить командой
`php yii regionsync/product/import-missing 9454 1`; для реального импорта одного
отсутствующего товара используется второй аргумент `0`. При таком импорте уже
категории и атрибуты полностью пропускаются (dry-run показывает их в `skippedCategories`
и `skippedAttributes`). Сначала
товар остаётся неопубликованным; публикация включается после сохранения связей
и файлов. Для полного переноса нужны миграции пакета (`tech_params`,
`price_items_sets`, расширение `product_category.position` до INT).

На локальном `yamaguchi-region.loc` соседний checkout пакета подключается напрямую
через автозагрузку и Yii alias; `apiHost` указывает на `http://yamaguchi.loc`.
Если checkout отсутствует, действует прежний Composer vendor и прежний `apiHost`.

```bash
# Один товар по itemId
php yii regionsync/product/sync-one 8790

# Один товар по goods.id основного сайта
php yii regionsync/product/sync-product 894

# Проверка всего каталога без записи (dry-run включён по умолчанию)
php yii regionsync/product/sync-all 100 "" 1

# Реальный инкрементальный импорт
php yii regionsync/product/sync-all 100 "2026-08-01 00:00:00" 0
```

Snapshot v1 переносит все реальные колонки `goods`, варианты, SEO, категории,
атрибуты, изображения, видео, связи, badges, search, QR, multigallery,
feed overrides и комплекты. Файлы проверяются по размеру и SHA-256, пути
ограничены каталогами конкретного товара. Необязательные таблицы старого
регионального проекта пропускаются с warning; отсутствие основных таблиц
откатывает импорт.

Web endpoint `/regionsync/import/sync-one` сохранён для существующей интеграции.
Он использует snapshot v1, а legacy-формат применяется только если донор отвечает
HTTP 404 на новый endpoint.

## Проверка

```bash
php tests/run.php
php tests/ImportOptions.php
composer validate --strict
```
