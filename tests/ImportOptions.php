<?php

// In-memory command spy: no application bootstrap, database or file writes.
require_once dirname(__DIR__) . '/src/services/FullProductImporter.php';

$db = new class {
    public $operations = [];

    public function beginTransaction()
    {
        return new class {
            public $isActive = true;
            public function commit() { $this->isActive = false; }
            public function rollBack() { $this->isActive = false; }
        };
    }

    public function getLastInsertID() { return 321; }

    public function createCommand($sql = null, $params = [])
    {
        if ($sql !== null) {
            $this->operations[] = ['sql', $sql, $params];
        }
        return new class($this) {
            private $db;
            public function __construct($db) { $this->db = $db; }
            public function queryScalar() { return false; }
            public function queryColumn()
            {
                return ['id', 'uri', 'published', 'productId', 'product_id', 'itemId', 'position',
                    'cat_in_bread_crumbs', 'category_id_for_compare', 'category_id_for_feed',
                    'name', 'value', 'sort'];
            }
            public function insert($table, $data)
            {
                $this->db->operations[] = ['insert', $table, $data];
                return $this;
            }
            public function update($table, $data, $where)
            {
                $this->db->operations[] = ['update', $table, $data];
                return $this;
            }
            public function delete($table, $where)
            {
                $this->db->operations[] = ['delete', $table, $where];
                return $this;
            }
            public function execute() { return 1; }
        };
    }
};
$reflection = new ReflectionClass('yamaguchi\regionsync\services\FullProductImporter');
$importer = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('db');
$property->setAccessible(true);
$property->setValue($importer, $db);
$snapshot = [
    'schemaVersion' => 1, 'snapshot' => true,
    'product' => [
        'goods' => ['uri' => 'test', 'published' => 1, 'cat_in_bread_crumbs' => 142,
            'category_id_for_compare' => 142, 'category_id_for_feed' => 142],
        'variants' => [['itemId' => 123, 'position' => 0]],
        'categories' => [['id' => 142, 'name' => 'Source category']],
        'categoryUrls' => [['param' => 142, 'alias' => 'source-category']],
        'productCategories' => [['categoryId' => 142]],
        'attributes' => [['id' => 211, 'name' => 'source_attribute']],
        'attributeGroups' => [['id' => 35, 'name' => 'source_group']],
        'productAttributes' => [['attribute_id' => 211, 'value' => '10']],
        'techParams' => [['name' => 'Power', 'value' => '10', 'sort' => 0]],
    ],
];
$result = $importer->import($snapshot, true, ['importCategories' => false, 'importAttributes' => false]);
if (empty($result['success'])) {
    throw new RuntimeException('Disabled classification import failed: ' . ($result['error'] ?? 'unknown'));
}
$forbidden = ['category', 'product_category', 'attribute', 'attribute_group', 'product_attribute'];
$goods = null;
$techParams = null;
foreach ($db->operations as $operation) {
    if ($operation[0] === 'sql') {
        if (in_array($operation[2][':table'] ?? '', $forbidden, true)) {
            throw new RuntimeException('Disabled dictionary was queried.');
        }
        continue;
    }
    if (in_array($operation[1], $forbidden, true)) {
        throw new RuntimeException('Disabled dictionary or relation was modified.');
    }
    if ($operation[1] === 'new_url' && $operation[0] === 'insert') {
        throw new RuntimeException('Category URL was imported.');
    }
    if ($operation[0] === 'insert' && $operation[1] === 'goods') { $goods = $operation[2]; }
    if ($operation[0] === 'insert' && $operation[1] === 'tech_params') { $techParams = $operation[2]; }
}
if ($goods === null || array_intersect_key($goods, array_flip([
    'cat_in_bread_crumbs', 'category_id_for_compare', 'category_id_for_feed',
]))) {
    throw new RuntimeException('Source category IDs leaked into goods.');
}
if (!$techParams || $techParams['value'] !== '10' || (string)$techParams['product_id'] !== '321') {
    throw new RuntimeException('Tech params were lost or not remapped.');
}
// Reusing the importer with the old signature must restore both default flags.
try { $importer->import([]); } catch (RuntimeException $expected) {}
foreach (['importCategories', 'importAttributes'] as $name) {
    $property = $reflection->getProperty($name);
    $property->setAccessible(true);
    if ($property->getValue($importer) !== true) {
        throw new RuntimeException('Legacy import default changed: ' . $name);
    }
}
echo "Import options OK: classification untouched, category IDs excluded, tech params retained, legacy defaults preserved\n";
