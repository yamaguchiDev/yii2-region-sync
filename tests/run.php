<?php

require_once dirname(__DIR__) . '/src/services/FullProductImporter.php';

$reflection = new ReflectionClass('yamaguchi\\regionsync\\services\\FullProductImporter');
$importer = $reflection->newInstanceWithoutConstructor();

$valid = [
    'schemaVersion' => 1,
    'snapshot' => true,
    'product' => [
        'goods' => ['uri' => 'test'],
        'variants' => [['itemId' => 1]],
    ],
];

if ($importer->validateSnapshot($valid)['goods']['uri'] !== 'test') {
    fwrite(STDERR, "Valid snapshot was rejected.\n");
    exit(1);
}

foreach ([[], ['schemaVersion' => 2], ['schemaVersion' => 1, 'snapshot' => true, 'product' => ['goods' => [], 'variants' => []]]] as $invalid) {
    try {
        $importer->validateSnapshot($invalid);
        fwrite(STDERR, "Invalid snapshot was accepted.\n");
        exit(1);
    } catch (RuntimeException $e) {
    }
}

fwrite(STDOUT, "Product snapshot contract: OK\n");
