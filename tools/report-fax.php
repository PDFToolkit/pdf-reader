<?php

declare(strict_types=1);

use PDF\Reading\ByteCursor;
use PDF\Reading\DocumentReader;
use PDF\Reading\Filters\CcittFaxDecoder;
use PDF\Reading\Filters\CcittFaxParameters;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
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
    $entry = ['bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
    if (($case['params']['EndOfBlock'] ?? true) || ($case['params']['Rows'] ?? 0) > 0) {
        $parameters = [];
        foreach ($case['params'] as $key => $value) {
            $parameters[lcfirst($key)] = $value;
        }
        $encoded = base64_decode($case['encoded'], true);
        if (!is_string($encoded)) {
            throw new RuntimeException('Invalid fixture encoding.');
        }
        $cursor = new ByteCursor('before' . $encoded . ' EI q Q after', 6);
        $prefix = (new CcittFaxDecoder(parameters: new CcittFaxParameters(...$parameters)))
            ->decodePrefix($cursor, new ReadBudget(new ReaderLimits()));
        $entry['prefix'] = [
            'bytes' => base64_encode($prefix->samples),
            'encodedBytes' => $prefix->encodedBytes,
            'following' => $cursor->read($cursor->remaining()),
            'rows' => $prefix->rows,
        ];
    }
    $output[$case['name']] = $entry;
}
echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
