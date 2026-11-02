<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\Inspection\InspectionLimits;

$path = $argv[1] ?? throw new InvalidArgumentException('Supply a local PDF fixture path.');
$profile = $argv[2] ?? 'complete';
$limits = match ($profile) {
    'complete' => new InspectionLimits(),
    'tiny' => new InspectionLimits(maxNodes: 2, maxObjects: 2, maxValues: 100, maxBytes: 128, maxDiagnostics: 3),
    'nodes' => new InspectionLimits(maxNodes: 2),
    'objects' => new InspectionLimits(maxObjects: 2),
    'values' => new InspectionLimits(maxValues: 100),
    'bytes' => new InspectionLimits(maxBytes: 4096),
    'strings' => new InspectionLimits(maxStringBytes: 8),
    'associations' => new InspectionLimits(maxAssociations: 1),
    'pages' => new InspectionLimits(maxPages: 1),
    'depth' => new InspectionLimits(maxDepth: 2),
    'diagnostics' => new InspectionLimits(maxDiagnostics: 2),
    default => throw new InvalidArgumentException('Unknown inspection validation profile.'),
};
$bytes = file_get_contents($path);
if (!is_string($bytes)) {
    throw new RuntimeException('Unable to read the local PDF fixture.');
}
$document = (new DocumentReader())->read(new FileBytes($bytes));
echo json_encode((new DocumentInspector($limits))->inspect($document), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
