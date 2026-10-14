<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    // An installed package uses its application's autoloader.
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
if (!is_file($autoload)) {
    throw new RuntimeException('Install the Composer dependencies before running this example.');
}
require $autoload;

// 1. The application chooses a local file. The reader receives only its bytes.
$path = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/page-tree/classic.pdf';
if (str_contains($path, '://') || str_contains($path, "\0")) {
    throw new InvalidArgumentException('Choose a local PDF path.');
}
$bytes = file_get_contents($path);
if ($bytes === false) {
    throw new RuntimeException('Could not read the chosen PDF.');
}
$document = (new DocumentReader())->read(new FileBytes($bytes));

// 2. This operation traverses the entire tree: Count and Parent are checked,
//    Kids order is preserved, and the four inheritable attributes are resolved.
//    These limits bound indirect traversal independently of syntax depth.
$tree = (new PageTreeReader($document, new PageTreeLimits(maxDepth: 64, maxNodes: 10000, maxPages: 5000)))->read();
printf("Verified pages: %d\n", count($tree));
$contentReader = new PageContentsReader($document, new PageContentLimits(maxStreams: 128));
foreach ($tree as $index => $page) {
    // 3. Effective values are core objects. The source dictionary retains only
    //    its local entries; it is neither modified nor imported.
    printf(
        "Page %d (%s R): MediaBox %s; CropBox %s; Rotate %d (orientation %d); resource categories %d\n",
        $index + 1,
        $page->reference->identifier,
        $page->attributes->mediaBox,
        $page->attributes->cropBox,
        $page->attributes->rotate->integer(),
        $page->attributes->normalizedRotation(),
        count($page->attributes->resources),
    );

    // 4. Contents belongs only to this page. Inspection preserves separate
    //    streams in order, including their filters and encoded bytes.
    $contents = $contentReader->read($page);
    printf("  Content streams: %d\n", count($contents));
    foreach ($contents as $ordinal => $stream) {
        // Decoding is requested here under the document's cumulative budgets.
        // The resulting bytes remain PDF instructions, without text extraction.
        $decoded = $document->streamData($stream);
        printf("  Stream %d: %d encoded bytes, %d decoded bytes\n", $ordinal + 1, strlen($stream->encodedBytes()), strlen($decoded));
    }
}
