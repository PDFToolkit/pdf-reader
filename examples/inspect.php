<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
if (!is_file($autoload)) {
    throw new RuntimeException('Install the Composer dependencies before running this example.');
}
require $autoload;

// The application chooses a local path; the reader only receives bytes.
$path = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/pdf/object-streams.pdf';
if (str_contains($path, '://') || str_contains($path, "\0")) {
    throw new InvalidArgumentException('Choose a local PDF path.');
}
$bytes = file_get_contents($path);
if ($bytes === false) {
    throw new RuntimeException('Could not read the chosen PDF.');
}
$document = (new DocumentReader())->read(new FileBytes($bytes));
$catalog = $document->catalog();
$pagesReference = $catalog->get('Pages');
if ($pagesReference === null) {
    throw new RuntimeException('The page-tree reference is missing.');
}
$pages = $document->resolve($pagesReference);
if (!$pages instanceof DictionaryType || !$pages->get('Count') instanceof IntegerType) {
    throw new RuntimeException('The page-tree count is missing.');
}
// This reads the declared count; complete page-tree validation is a separate operation.
printf("PDF %s; declared page count: %d\n", $document->header()->version->value, $pages->get('Count')->integer());
