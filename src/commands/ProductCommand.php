<?php

namespace yamaguchi\regionsync\commands;

use yii\console\Controller;
use yii\console\ExitCode;
use yamaguchi\regionsync\services\FullProductImporter;
use yamaguchi\regionsync\services\ProductSnapshotClient;
use yamaguchi\regionsync\services\ProductDiffService;
use yamaguchi\regionsync\services\NewProductImportService;

class ProductCommand extends Controller
{
    /** Dry-run by default; pass 0 as second argument to import one new product. */
    public function actionImportMissing($itemId, $dryRun = 1)
    {
        try {
            $result = (new NewProductImportService())->importOne((int)$itemId, (bool)$dryRun);
        } catch (\Throwable $exception) {
            $this->stderr('Import skipped: ' . $exception->getMessage() . PHP_EOL);
            return ExitCode::DATAERR;
        }
        $this->stdout(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return !empty($result['success']) ? ExitCode::OK : ExitCode::DATAERR;
    }

    /** Read-only primary itemId comparison, shared with the web dashboard. */
    public function actionDiff()
    {
        $diff = (new ProductDiffService())->compare();
        $this->stdout(json_encode([
            'sourceCount' => $diff['sourceCount'], 'localCount' => $diff['localCount'],
            'missingCount' => count($diff['missing']), 'conflictCount' => count($diff['conflicts']),
            'localOnlyCount' => count($diff['localOnly']),
            'missingSample' => array_slice(array_values($diff['missing']), 0, 10),
            'conflictItemIds' => array_keys($diff['conflicts']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return ExitCode::OK;
    }

    public function actionSyncOne($itemId)
    {
        return $this->syncSnapshot((new ProductSnapshotClient())->fetchOne((int)$itemId));
    }

    public function actionSyncProduct($productId)
    {
        return $this->syncSnapshot((new ProductSnapshotClient())->fetchOne(null, (int)$productId));
    }

    public function actionSyncAll($limit = 100, $updatedAfter = null, $dryRun = 1)
    {
        $client = new ProductSnapshotClient();
        $importer = new FullProductImporter();
        $cursor = 0;
        $total = 0;
        $failed = 0;
        do {
            $page = $client->fetchIds($cursor, max(1, min(500, (int)$limit)), $updatedAfter);
            foreach ($page['items'] ?? [] as $item) {
                try {
                    $snapshot = $client->fetchOne(null, (int)$item['productId'], !(bool)$dryRun);
                    if ($dryRun) {
                        $importer->validateSnapshot($snapshot);
                    } else {
                        $result = $importer->import($snapshot);
                        if (empty($result['success'])) {
                            throw new \RuntimeException($result['error'] ?? 'Import failed');
                        }
                    }
                    $total++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->stderr('productId=' . $item['productId'] . ': ' . $e->getMessage() . PHP_EOL);
                }
            }
            $cursor = (int)($page['nextCursor'] ?? 0);
        } while (!empty($page['hasMore']) && $cursor > 0);

        $this->stdout('processed=' . $total . ' failed=' . $failed . ' dryRun=' . ((int)(bool)$dryRun) . PHP_EOL);
        return $failed ? ExitCode::DATAERR : ExitCode::OK;
    }

    private function syncSnapshot(array $snapshot)
    {
        $result = (new FullProductImporter())->import($snapshot);
        $this->stdout(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return !empty($result['success']) ? ExitCode::OK : ExitCode::DATAERR;
    }
}
