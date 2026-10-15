<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\DictionaryType;

require dirname(__DIR__) . '/vendor/autoload.php';
$report = [];
foreach (array_slice($argv, 1) as $filename) {
    try {
        $bytes = file_get_contents($filename);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read the source fixture.');
        }
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read(new FileBytes($bytes));
        $pages = (new PageTreeReader($document))->read();
        $content = '';
        foreach ($pages as $page) {
            foreach ((new PageContentsReader($document))->read($page) as $stream) {
                $content .= $document->streamData($stream);
            }
        }
        $binary = $document->catalog()->get('FixtureBinary');
        $compressed = $document->catalog()->get('FixtureCompressed');
        $compressed = $compressed === null ? null : $document->resolve($compressed);
        $value = $compressed instanceof DictionaryType ? $compressed->get('Value') : null;
        $report[] = ['ok' => true, 'mac' => $document->mac()?->value, 'pages' => count($pages), 'binary' => $binary instanceof StringType ? bin2hex($binary->value) : null, 'contents' => $content, 'compressed' => $value instanceof StringType ? $value->value : null];
    } catch (Throwable $error) {
        $report[] = ['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
