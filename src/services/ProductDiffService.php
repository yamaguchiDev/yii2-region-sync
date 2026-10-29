<?php

namespace yamaguchi\regionsync\services;

use Yii;
use yii\db\Query;

/** Read-only catalog comparison. All lookups use primary (position=0) itemIds. */
class ProductDiffService
{
    private $client;

    public function __construct(ProductSnapshotClient $client = null)
    {
        $this->client = $client ?: new ProductSnapshotClient();
    }

    public function compare(): array
    {
        $localRows = (new Query())->select(['itemId', 'productId'])
            ->from('new_product_variant')
            ->where(['position' => 0])->andWhere(['>', 'itemId', 0])->all();
        $local = [];
        foreach ($localRows as $row) {
            $local[(int)$row['itemId']][] = (int)$row['productId'];
        }

        $remote = [];
        $cursor = 0;
        do {
            $page = $this->client->fetchPrimaryItemIds($cursor, 100);
            if (!is_array($page['items'] ?? null) || !isset($page['hasMore'])) {
                throw new ProductSnapshotException('Invalid primary itemId response.');
            }
            foreach ($page['items'] as $row) {
                $itemId = (int)($row['itemId'] ?? 0);
                if ($itemId > 0 && (int)($row['variantId'] ?? 0) > $cursor) {
                    $remote[$itemId][] = [
                        'itemId' => $itemId,
                        'productId' => (int)$row['productId'],
                        'variantId' => (int)$row['variantId'],
                        'name' => (string)$row['name'],
                        'uri' => (string)$row['uri'],
                    ];
                }
            }
            $nextCursor = (int)($page['nextCursor'] ?? 0);
            if (!empty($page['hasMore']) && $nextCursor <= $cursor) {
                throw new ProductSnapshotException('Primary itemId cursor did not advance.');
            }
            $cursor = $nextCursor;
        } while (!empty($page['hasMore']));

        $missing = [];
        $conflicts = [];
        foreach ($remote as $itemId => $products) {
            if (count($products) !== 1 || count($local[$itemId] ?? []) > 1) {
                $conflicts[$itemId] = ['source' => $products, 'localProductIds' => $local[$itemId] ?? []];
            } elseif (!isset($local[$itemId])) {
                $missing[$itemId] = $products[0];
            }
        }
        $localOnly = [];
        foreach ($local as $itemId => $products) {
            if (!isset($remote[$itemId])) {
                $localOnly[$itemId] = $products;
            }
        }

        ksort($missing, SORT_NUMERIC);
        ksort($conflicts, SORT_NUMERIC);
        ksort($localOnly, SORT_NUMERIC);
        return [
            'missing' => $missing,
            'conflicts' => $conflicts,
            'localOnly' => $localOnly,
            'sourceCount' => array_sum(array_map('count', $remote)),
            'localCount' => count($localRows),
        ];
    }
}
