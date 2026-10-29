<?php

namespace yamaguchi\regionsync\services;

use RuntimeException;
use Yii;
use yii\helpers\FileHelper;

class FullProductImporter
{
    private $db;
    private $columns = [];
    private $warnings = [];
    private $stagedFiles = [];
    private $installedFiles = [];
    private $preserveSharedDictionaries = false;
    private $importCategories = true;
    private $importAttributes = true;

    public function __construct()
    {
        $this->db = Yii::$app->db;
    }

    public function validateSnapshot(array $snapshot)
    {
        if (($snapshot['schemaVersion'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported product snapshot schemaVersion.');
        }
        if (empty($snapshot['snapshot']) || !is_array($snapshot['product'] ?? null)) {
            throw new RuntimeException('Invalid product snapshot envelope.');
        }
        $product = $snapshot['product'];
        if (!is_array($product['goods'] ?? null) || !is_array($product['variants'] ?? null) || !$product['variants']) {
            throw new RuntimeException('Product snapshot must contain goods and variants.');
        }
        return $product;
    }

    public function import(array $snapshot, $preserveSharedDictionaries = false, array $options = [])
    {
        $this->preserveSharedDictionaries = (bool)$preserveSharedDictionaries;
        $this->importCategories = (bool)($options['importCategories'] ?? true);
        $this->importAttributes = (bool)($options['importAttributes'] ?? true);
        $this->warnings = [];
        $product = $this->validateSnapshot($snapshot);
        $transaction = null;

        try {
            $this->stageFiles($product);
            $transaction = $this->db->beginTransaction();
            $productId = $this->saveGoods($product['goods'], $product['variants']);
            $this->syncDictionaries($product);
            $categoryMap = $this->syncCategories($product);
            $attributeMap = $this->syncAttributes($product);

            $this->replaceSeo($productId, $product['seo'] ?? []);
            if ($this->importCategories) {
                $this->replaceMappedLinks('product_category', ['productId' => $productId], $product['productCategories'] ?? [], 'categoryId', $categoryMap, true);
            }
            if ($this->importAttributes) {
                $this->replaceMappedLinks('product_attribute', ['product_id' => $productId], $product['productAttributes'] ?? [], 'attribute_id', $attributeMap, true);
            }
            $imageMap = $this->replaceRows('new_image', ['nodeId' => $productId, 'nodeType' => 'product'], $product['images'] ?? [], ['nodeId' => $productId, 'nodeType' => 'product'], true, ['file']);
            $variants = $product['variants'];
            foreach ($variants as &$variant) {
                $sourceImageId = (int)($variant['imageId'] ?? 0);
                if ($sourceImageId && isset($imageMap[$sourceImageId])) {
                    $variant['imageId'] = $imageMap[$sourceImageId];
                }
            }
            unset($variant);
            $this->replaceRows('new_product_variant', ['productId' => $productId], $variants, ['productId' => $productId], true);
            $videoMap = $this->replaceRows('new_product_video', ['productId' => $productId], $product['videos'] ?? [], ['productId' => $productId], false);
            $multigalleryImageMap = $this->syncMultigalleryImages($product, $productId);
            $multigalleryPositions = $product['multigalleryPositions'] ?? [];
            foreach ($multigalleryPositions as &$position) {
                $sourceNodeId = (int)($position['nodeId'] ?? 0);
                if (($position['nodeType'] ?? null) === 'video' && isset($videoMap[$sourceNodeId])) {
                    $position['nodeId'] = $videoMap[$sourceNodeId];
                } elseif (($position['nodeType'] ?? null) === 'image' && isset($multigalleryImageMap[$sourceNodeId])) {
                    $position['nodeId'] = $multigalleryImageMap[$sourceNodeId];
                }
            }
            unset($position);
            $this->replaceRows('multigallery_positions', ['productId' => $productId], $multigalleryPositions, ['productId' => $productId], false);
            $itemIds = array_values(array_filter(array_map('intval', array_column($product['variants'], 'itemId'))));
            if ($itemIds && $this->tableExists('price_items_sets')) {
                $this->db->createCommand()->delete('price_items_sets', ['main_item_id' => $itemIds])->execute();
                foreach ($product['sets'] ?? [] as $row) {
                    $data = $this->filterRow('price_items_sets', $row, ['id']);
                    if ($data) {
                        $this->db->createCommand()->insert('price_items_sets', $data)->execute();
                    }
                }
            }

            $collections = [
                ['new_product_video_comment', ['productId' => $productId], 'videoComments', ['productId' => $productId]],
                ['product_custom_image', ['productId' => $productId], 'avatars', ['productId' => $productId]],
                ['expert_comment', ['productId' => $productId], 'expertComments', ['productId' => $productId]],
                ['relation', ['nodeId' => $productId, 'nodeType' => ['product', 'isLanding']], 'relations', ['nodeId' => $productId]],
                ['product_needed', ['product_id' => $productId], 'neededProducts', ['product_id' => $productId]],
                ['product_product_badge', ['product_id' => $productId], 'badges', ['product_id' => $productId]],
                ['search', ['nodeId' => $productId, 'nodeType' => 'product'], 'search', ['nodeId' => $productId, 'nodeType' => 'product']],
                ['qrcode', ['good_id' => $productId], 'qrCodes', ['good_id' => $productId]],
                ['yandex_small_image_feed_text', ['product_id' => $productId], 'feedOverrides', ['product_id' => $productId]],
                ['tech_params', ['product_id' => (string)$productId], 'techParams', ['product_id' => (string)$productId]],
            ];
            foreach ($collections as $definition) {
                // Older donors do not export this section. Do not delete existing local values.
                if ($definition[2] === 'techParams' && !array_key_exists('techParams', $product)) {
                    continue;
                }
                $rows = $product[$definition[2]] ?? [];
                if ($rows && $this->isAssoc($rows)) {
                    $rows = [$rows];
                }
                $this->replaceRows($definition[0], $definition[1], is_array($rows) ? $rows : [], $definition[3], false);
            }

            $this->installStagedFiles($productId, (int)($product['sourceProductId'] ?? 0));
            if ($this->preserveSharedDictionaries) {
                $this->db->createCommand()->update('goods', [
                    'published' => (int)($product['goods']['published'] ?? 0),
                ], ['id' => $productId])->execute();
            }
            $transaction->commit();
            $this->discardBackups();

            return ['success' => true, 'productId' => $productId, 'warnings' => $this->warnings];
        } catch (\Throwable $e) {
            if ($transaction && $transaction->isActive) {
                $transaction->rollBack();
            }
            $this->restoreInstalledFiles();
            $this->cleanupStagedFiles();
            return ['success' => false, 'error' => $e->getMessage(), 'warnings' => $this->warnings];
        }
    }

    private function syncDictionaries(array $product)
    {
        foreach (['variantGroups' => 'new_variant_group', 'variantDefinitions' => 'new_variant', 'badgeGroups' => 'product_badge_group', 'badgeDefinitions' => 'product_badge'] as $section => $table) {
            foreach ($product[$section] ?? [] as $row) {
                $this->upsertDictionary($table, $row, $row['id'] ?? null, false);
            }
        }
    }

    private function syncMultigalleryImages(array $product, $productId)
    {
        $map = [];
        foreach ($product['multigalleryImages'] ?? [] as $row) {
            if (!$this->tableExists('images')) {
                $this->warnings[] = 'Optional table images is missing.';
                break;
            }
            $sourceId = (int)($row['id'] ?? 0);
            if (isset($this->tableColumns('images')['modelId'])) {
                $row['modelId'] = $productId;
            }
            unset($row['webpFile'], $row['originalFile']);
            if (isset($this->tableColumns('images')['folder'])) {
                $row['folder'] = '/images-new/products/' . $productId . '/';
            }
            $where = [];
            foreach (['model', 'modelId', 'name'] as $column) {
                if (isset($row[$column]) && isset($this->tableColumns('images')[$column])) {
                    $where[$column] = $row[$column];
                }
            }
            $localId = $where ? (new \yii\db\Query())->select('id')->from('images')->where($where)->scalar($this->db) : null;
            $data = $this->filterRow('images', $row, ['id']);
            if ($localId) {
                $this->db->createCommand()->update('images', $data, ['id' => $localId])->execute();
            } else {
                $this->db->createCommand()->insert('images', $data)->execute();
                $localId = $this->db->getLastInsertID();
            }
            if ($sourceId) {
                $map[$sourceId] = (int)$localId;
            }
        }
        return $map;
    }

    private function saveGoods(array $goods, array $variants)
    {
        $itemIds = array_values(array_filter(array_map('intval', array_column($variants, 'itemId'))));
        $productId = null;
        if ($itemIds) {
            $productId = $this->db->createCommand('SELECT productId FROM new_product_variant WHERE itemId IN (' . implode(',', $itemIds) . ') ORDER BY position, id LIMIT 1')->queryScalar();
        }
        if (!$productId && !empty($goods['uri'])) {
            $productId = $this->db->createCommand('SELECT id FROM goods WHERE uri = :uri LIMIT 1', [':uri' => $goods['uri']])->queryScalar();
        }

        $excluded = ['id'];
        if (!$this->importCategories) {
            // These are foreign IDs too, even though they are stored directly in goods.
            $excluded = array_merge($excluded, ['cat_in_bread_crumbs', 'category_id_for_compare', 'category_id_for_feed']);
        }
        $data = $this->filterRow('goods', $goods, $excluded);
        if ($this->preserveSharedDictionaries) {
            // A partial MyISAM import must never expose a published product before its relations exist.
            $data['published'] = 0;
        }
        if ($productId) {
            $this->db->createCommand()->update('goods', $data, ['id' => $productId])->execute();
            return (int)$productId;
        }
        $this->db->createCommand()->insert('goods', $data)->execute();
        return (int)$this->db->getLastInsertID();
    }

    private function syncCategories(array $product)
    {
        if (!$this->importCategories) {
            return [];
        }
        $urls = [];
        foreach ($product['categoryUrls'] ?? [] as $url) {
            $urls[(int)($url['param'] ?? 0)] = $url;
        }
        $map = [];
        $existingCategoryIds = [];
        foreach ($product['categories'] ?? [] as $row) {
            $sourceId = (int)($row['id'] ?? 0);
            $localId = $sourceId && $this->exists('category', ['id' => $sourceId]) ? $sourceId : null;
            if (!$localId && isset($urls[$sourceId]['alias']) && $this->tableExists('new_url')) {
                $localId = $this->db->createCommand('SELECT param FROM new_url WHERE route = :route AND alias = :alias LIMIT 1', [':route' => 'category/site/view', ':alias' => $urls[$sourceId]['alias']])->queryScalar();
            }
            if (!$localId && !empty($row['name'])) {
                $localId = $this->db->createCommand('SELECT id FROM category WHERE name = :name LIMIT 1', [':name' => $row['name']])->queryScalar();
            }
            if ($localId && $this->preserveSharedDictionaries) {
                $existingCategoryIds[(int)$localId] = true;
            }
            $localId = $this->upsertDictionary('category', $row, $localId);
            if ($sourceId) {
                $map[$sourceId] = $localId;
            }
        }
        foreach ($product['categories'] ?? [] as $row) {
            $sourceId = (int)($row['id'] ?? 0);
            $localId = $map[$sourceId] ?? null;
            if ($localId && isset($existingCategoryIds[(int)$localId])) {
                continue;
            }
            $sourceParentId = (int)($row['parent_id'] ?? 0);
            if ($localId && $sourceParentId && isset($map[$sourceParentId]) && isset($this->tableColumns('category')['parent_id'])) {
                $this->db->createCommand()->update('category', ['parent_id' => $map[$sourceParentId]], ['id' => $localId])->execute();
            }
            if ($localId && isset($urls[$sourceId])) {
                $url = $urls[$sourceId];
                $url['route'] = 'category/site/view';
                $url['param'] = $localId;
                $existingId = !empty($url['alias']) ? $this->db->createCommand('SELECT id FROM new_url WHERE route = :route AND alias = :alias LIMIT 1', [':route' => 'category/site/view', ':alias' => $url['alias']])->queryScalar() : null;
                $data = $this->filterRow('new_url', $url, ['id']);
                if ($existingId) {
                    $this->db->createCommand()->update('new_url', $data, ['id' => $existingId])->execute();
                } else {
                    $this->db->createCommand()->insert('new_url', $data)->execute();
                }
            }
        }
        return $map;
    }

    private function syncAttributes(array $product)
    {
        if (!$this->importAttributes) {
            return [];
        }
        foreach ($product['attributeGroups'] ?? [] as $row) {
            $this->upsertDictionary('attribute_group', $row, $row['id'] ?? null, false);
        }
        $map = [];
        foreach ($product['attributes'] ?? [] as $row) {
            $sourceId = (int)($row['id'] ?? 0);
            $localId = $sourceId && $this->exists('attribute', ['id' => $sourceId]) ? $sourceId : null;
            if (!$localId && !empty($row['name'])) {
                $localId = $this->db->createCommand('SELECT id FROM attribute WHERE name = :name LIMIT 1', [':name' => $row['name']])->queryScalar();
            }
            $localId = $this->upsertDictionary('attribute', $row, $localId);
            if ($sourceId) {
                $map[$sourceId] = $localId;
            }
        }
        return $map;
    }

    private function upsertDictionary($table, array $row, $localId = null, $critical = true)
    {
        if (!$this->tableExists($table)) {
            if ($critical) {
                throw new RuntimeException("Required table {$table} is missing.");
            }
            $this->warnings[] = "Optional table {$table} is missing.";
            return null;
        }
        $sourceId = $row['id'] ?? null;
        if ($localId && !$this->exists($table, ['id' => $localId])) {
            $localId = null;
        }
        $data = $this->filterRow($table, $row, ['id']);
        if ($localId) {
            if ($data && !$this->preserveSharedDictionaries) {
                $this->db->createCommand()->update($table, $data, ['id' => $localId])->execute();
            }
            return (int)$localId;
        }
        if ($sourceId && !$this->exists($table, ['id' => $sourceId])) {
            $data['id'] = $sourceId;
        }
        $this->db->createCommand()->insert($table, $data)->execute();
        return (int)($data['id'] ?? $this->db->getLastInsertID());
    }

    private function replaceSeo($productId, array $seo)
    {
        $this->assertTable('new_url');
        $this->db->createCommand()->delete('new_url', ['route' => 'product/site/view', 'param' => $productId])->execute();
        if ($seo) {
            $seo['route'] = 'product/site/view';
            $seo['param'] = $productId;
            $data = $this->filterRow('new_url', $seo, ['id']);
            $this->db->createCommand()->insert('new_url', $data)->execute();
        }
    }

    private function replaceMappedLinks($table, array $where, array $rows, $field, array $map, $critical)
    {
        foreach ($rows as &$row) {
            $sourceId = (int)($row[$field] ?? 0);
            if (isset($map[$sourceId])) {
                $row[$field] = $map[$sourceId];
            }
        }
        unset($row);
        $this->replaceRows($table, $where, $rows, $where, $critical);
    }

    private function replaceRows($table, array $where, array $rows, array $forced, $critical, array $excluded = [])
    {
        if (!$this->tableExists($table)) {
            if ($critical) {
                throw new RuntimeException("Required table {$table} is missing.");
            }
            $this->warnings[] = "Optional table {$table} is missing.";
            return [];
        }
        $map = [];
        $this->db->createCommand()->delete($table, $where)->execute();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceId = (int)($row['id'] ?? 0);
            foreach ($excluded as $key) {
                unset($row[$key]);
            }
            $row = array_merge($row, $forced);
            $data = $this->filterRow($table, $row, ['id']);
            if ($data) {
                $this->db->createCommand()->insert($table, $data)->execute();
                if ($sourceId) {
                    $map[$sourceId] = (int)$this->db->getLastInsertID();
                }
            }
        }
        return $map;
    }

    private function filterRow($table, array $row, array $excluded)
    {
        $columns = $this->tableColumns($table);
        foreach ($excluded as $column) {
            unset($columns[$column]);
        }
        return array_intersect_key($row, $columns);
    }

    private function tableColumns($table)
    {
        if (!isset($this->columns[$table])) {
            $names = $this->db->createCommand('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', [':table' => $table])->queryColumn();
            $this->columns[$table] = array_fill_keys($names, true);
        }
        return $this->columns[$table];
    }

    private function tableExists($table)
    {
        return $this->tableColumns($table) !== [];
    }

    private function assertTable($table)
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    private function exists($table, array $where)
    {
        return $this->tableExists($table) && (new \yii\db\Query())->from($table)->where($where)->exists($this->db);
    }

    private function stageFiles(array $product)
    {
        $manifests = [];
        foreach ($product['images'] ?? [] as $image) {
            if (is_array($image['file'] ?? null)) {
                $manifests[] = $image['file'];
            }
        }
        foreach ($product['mainVideos'] ?? [] as $file) {
            if (is_array($file)) {
                $manifests[] = $file;
            }
        }
        foreach ($product['multigalleryImages'] ?? [] as $image) {
            foreach (['webpFile', 'originalFile'] as $field) {
                if (is_array($image[$field] ?? null)) {
                    $manifests[] = $image[$field];
                }
            }
        }
        $uri = basename((string)($product['goods']['uri'] ?? ''));
        $sourceId = (int)($product['sourceProductId'] ?? 0);
        foreach ($manifests as $manifest) {
            if (empty($manifest['exists']) || !isset($manifest['data'])) {
                continue;
            }
            $relative = str_replace('\\', '/', (string)($manifest['relativePath'] ?? ''));
            $allowed = ['images/product/' . $uri . '/', 'video/' . $uri . '/main/', 'images-new/products/' . $sourceId . '/'];
            if ($uri === '' || strpos($relative, '..') !== false
                || ($sourceId < 1 && strpos($relative, $allowed[2]) === 0)
                || (strpos($relative, $allowed[0]) !== 0 && strpos($relative, $allowed[1]) !== 0 && strpos($relative, $allowed[2]) !== 0)
                || basename($relative) === '') {
                throw new RuntimeException('Unsafe file path in product snapshot.');
            }
            $binary = base64_decode((string)$manifest['data'], true);
            if ($binary === false || (isset($manifest['size']) && strlen($binary) !== (int)$manifest['size']) || (isset($manifest['sha256']) && !hash_equals((string)$manifest['sha256'], hash('sha256', $binary)))) {
                throw new RuntimeException('Invalid product snapshot file payload.');
            }
            $tempDir = Yii::getAlias('@runtime/region-sync-import');
            FileHelper::createDirectory($tempDir, 0775, true);
            $temp = tempnam($tempDir, 'product-');
            if ($temp === false || file_put_contents($temp, $binary) === false) {
                throw new RuntimeException('Unable to stage product snapshot file.');
            }
            $this->stagedFiles[] = ['temp' => $temp, 'relative' => $relative];
        }
    }

    private function installStagedFiles(int $productId, int $sourceId)
    {
        foreach ($this->stagedFiles as $file) {
            $relative = $file['relative'];
            $galleryPrefix = 'images-new/products/' . $sourceId . '/';
            if (strpos($relative, $galleryPrefix) === 0) {
                $relative = 'images-new/products/' . $productId . '/' . substr($relative, strlen($galleryPrefix));
            }
            $destination = Yii::getAlias('@webroot/' . $relative);
            FileHelper::createDirectory(dirname($destination), 0775, true);
            $backup = null;
            if (is_file($destination)) {
                $backup = $destination . '.regionsync-' . uniqid('', true) . '.bak';
                if (!rename($destination, $backup)) {
                    throw new RuntimeException('Unable to backup existing product file.');
                }
            }
            if (!rename($file['temp'], $destination)) {
                if ($backup) {
                    rename($backup, $destination);
                }
                throw new RuntimeException('Unable to install product snapshot file.');
            }
            $this->installedFiles[] = ['destination' => $destination, 'backup' => $backup];
        }
        $this->stagedFiles = [];
    }

    private function restoreInstalledFiles()
    {
        foreach (array_reverse($this->installedFiles) as $file) {
            if (is_file($file['destination'])) {
                unlink($file['destination']);
            }
            if ($file['backup'] && is_file($file['backup'])) {
                rename($file['backup'], $file['destination']);
            }
        }
        $this->installedFiles = [];
    }

    private function discardBackups()
    {
        foreach ($this->installedFiles as $file) {
            if ($file['backup'] && is_file($file['backup'])) {
                unlink($file['backup']);
            }
        }
        $this->installedFiles = [];
    }

    private function cleanupStagedFiles()
    {
        foreach ($this->stagedFiles as $file) {
            if (is_file($file['temp'])) {
                unlink($file['temp']);
            }
        }
        $this->stagedFiles = [];
    }

    private function isAssoc(array $array)
    {
        return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
    }
}
