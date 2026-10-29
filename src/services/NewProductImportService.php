<?php

namespace yamaguchi\regionsync\services;

use yii\db\Query;

/** Import only a new, uniquely identified published product; never overwrite shared dictionaries. */
class NewProductImportService
{
    private const IMPORT_OPTIONS = ['importCategories' => false, 'importAttributes' => false];

    public function importOne(int $itemId, bool $dryRun = true): array
    {
        if ($itemId <= 0) {
            throw new \InvalidArgumentException('itemId must be positive.');
        }
        $diff = (new ProductDiffService())->compare();
        if (!isset($diff['missing'][$itemId]) || isset($diff['conflicts'][$itemId])) {
            throw new \RuntimeException('ItemId is not uniquely missing on this site.');
        }

        $client = new ProductSnapshotClient();
        // The legacy itemId lookup also sees secondary and unpublished variants.
        // Fetch the exact published product selected by the primary-item comparison.
        $sourceProductId = (int)$diff['missing'][$itemId]['productId'];
        $preview = $client->fetchOne(null, $sourceProductId, false);
        $this->assertSafeNewProduct($itemId, $preview);
        $product = $preview['product'];
        if ($dryRun) {
            return [
                'success' => true, 'dryRun' => true, 'itemId' => $itemId,
                'sourceProductId' => (int)$product['sourceProductId'],
                'uri' => $product['goods']['uri'],
                'images' => count($product['images'] ?? []),
                'categories' => 0,
                'attributes' => 0,
                'skippedCategories' => count($product['productCategories'] ?? []),
                'skippedAttributes' => count($product['productAttributes'] ?? []),
                'importOptions' => self::IMPORT_OPTIONS,
                'techParams' => count($product['techParams'] ?? []),
            ];
        }

        $snapshot = $client->fetchOne(null, $sourceProductId, true);
        $this->assertSafeNewProduct($itemId, $snapshot);
        if ((int)$snapshot['product']['sourceProductId'] !== (int)$product['sourceProductId']) {
            throw new \RuntimeException('Source product changed between preview and import.');
        }
        return (new FullProductImporter())->import($snapshot, true, self::IMPORT_OPTIONS);
    }

    private function assertSafeNewProduct(int $itemId, array $snapshot): void
    {
        $product = (new FullProductImporter())->validateSnapshot($snapshot);
        $goods = $product['goods'];
        $primary = [];
        foreach ($product['variants'] as $variant) {
            if ((int)($variant['position'] ?? -1) === 0) {
                $primary[] = (int)($variant['itemId'] ?? 0);
            }
        }
        if ((int)($goods['published'] ?? 0) !== 1 || $primary !== [$itemId]
            || !empty($goods['deleted_at'])
            || (int)($product['sourceProductId'] ?? 0) < 1 || empty($goods['uri'])) {
            throw new \RuntimeException('Snapshot does not contain one published primary itemId.');
        }
        $variantIds = array_values(array_filter(array_map('intval', array_column($product['variants'], 'itemId'))));
        if ((new Query())->from('new_product_variant')->where(['itemId' => $variantIds])->exists()
            || (new Query())->from('goods')->where(['uri' => $goods['uri']])->exists()) {
            throw new \RuntimeException('A product or one of its variants already exists locally.');
        }
        $this->assertDictionaryIdentity($product);
    }

    /** Fail before any write when equal numeric IDs refer to different dictionary entries. */
    public function assertDictionaryIdentity(array $product): void
    {
        $sections = [
            'variantDefinitions' => ['new_variant', 'name'],
            'variantGroups' => ['new_variant_group', 'name'],
        ];
        foreach ($sections as $section => $definition) {
            list($table, $key) = $definition;
            foreach ($product[$section] ?? [] as $row) {
                $local = (new Query())->from($table)->where(['id' => (int)$row['id']])->one();
                if ($local && (!isset($row[$key], $local[$key]) || (string)$row[$key] !== (string)$local[$key])) {
                    throw new \RuntimeException('Dictionary conflict: ' . $table . '.id=' . (int)$row['id']
                        . '. Configure source-to-local mapping before importing.');
                }
            }
        }
    }
}
