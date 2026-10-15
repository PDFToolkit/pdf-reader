<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__) . '/vendor/autoload.php';
$bytes = file_get_contents($argv[1]);
if ($bytes === false) {
    throw new RuntimeException('Fixture unavailable.');
}
$password = (int) $argv[2] <= 4 ? new PdfDocEncodedPassword(new HexadecimalString('user')) : new PreparedPassword('user');
$document = (new DocumentReader(authentication: new StandardPasswordAuthentication($password)))->read(new FileBytes($bytes));
$catalog = $document->catalog();
$report = [];
foreach (['Metadata', 'FixturePageMetadata', 'FixtureObjectMetadata', 'FixtureClearMetadata', 'FixtureEmbedded'] as $name) {
    $reference = $catalog->get($name);
    if ($reference === null) {
        continue;
    }
    $stream = $document->resolve($reference);
    if (!$stream instanceof EncodedStream) {
        throw new RuntimeException('Fixture stream missing.');
    }
    $report[$name] = base64_encode($document->streamData($stream));
}
if ($catalog->get('FixturePage') !== null) {
    $page = $document->resolve($catalog->get('FixturePage'));
    if (!$page instanceof DictionaryType || $page->get('Metadata') === null) {
        throw new RuntimeException('Fixture page missing.');
    }
    $stream = $document->resolve($page->get('Metadata'));
    if (!$stream instanceof EncodedStream) {
        throw new RuntimeException('Fixture page metadata missing.');
    }
    $report['PageMetadata'] = base64_encode($document->streamData($stream));
}
if (($argv[3] ?? null) === '--credentials') {
    $encryption = $document->resolve($document->trailer()->get('Encrypt'));
    if (!$encryption instanceof DictionaryType) {
        throw new RuntimeException('The terminal encryption dictionary is missing.');
    }
    foreach (['O', 'U'] as $name) {
        $value = $encryption->get($name);
        if (!$value instanceof \PDF\Syntax\Object\StringType) {
            throw new RuntimeException('A retained encryption credential is missing.');
        }
        $report['Encryption' . $name] = base64_encode($value->value);
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
