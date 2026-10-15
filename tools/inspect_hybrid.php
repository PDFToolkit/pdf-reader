<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

$path = $argv[1] ?? throw new InvalidArgumentException('Supply a local fixture path.');
$document = (new DocumentReader())->read(new FileBytes(file_get_contents($path)));
$catalog = $document->catalog();
$outline = $document->resolve($catalog->get('Outlines'));
$first = $outline instanceof DictionaryType ? $document->resolve($outline->get('First')) : null;
$title = $first instanceof DictionaryType ? $document->resolve($first->get('Title')) : null;
$piece = $catalog->get('PieceInfo');
$piece = $piece === null ? null : $document->resolve($piece);
$value = $piece instanceof DictionaryType ? $piece->get('Private') : null;
$pages = [];
foreach ((new PageTreeReader($document))->read() as $page) {
    $content = '';
    foreach ((new PageContentsReader($document))->read($page) as $stream) {
        $content .= $document->streamData($stream);
    }
    $pages[] = hash('sha256', $content);
}
echo json_encode(['pages' => $pages, 'outline' => $title instanceof StringType ? $title->value : null,
    'piece' => $value instanceof IntegerType ? $value->integer() : null], JSON_THROW_ON_ERROR);
