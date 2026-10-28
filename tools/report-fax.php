<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PDF\Syntax\Object\DictionaryType;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__) . '/tests/Fixtures/fax';
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$output = [];
foreach ($manifest['cases'] as $case) {
    $document = (new DocumentReader())->read(new FileBytes((string) file_get_contents($root . '/' . $case['name'] . '.pdf')));
    $page = (new PageTreeReader($document))->read()->page(0);
    $resources = $document->resolve($page->attributes->resources->get('XObject') ?? throw new RuntimeException('No image resources.'));
    if (!$resources instanceof DictionaryType) {
        throw new RuntimeException('Invalid image resources.');
    }
    $image = $document->resolve($resources->get('Fax') ?? throw new RuntimeException('No fax image.'));
    if (!$image instanceof EncodedStream) {
        throw new RuntimeException('Invalid fax image.');
    }
    $bytes = $document->streamData($image);
    $output[$case['name']] = ['bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
}
echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
