<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

// These are public, original fixture credentials, never application secrets.
$path = $argv[1] ?? throw new InvalidArgumentException('A fixture path is required.');
$revision = (int) ($argv[2] ?? 0);
$password = $argv[3] ?? 'user';
$credential = $revision <= 4 ? new PdfDocEncodedPassword(new HexadecimalString($password)) : new PreparedPassword($password);
$document = (new DocumentReader(authentication: new StandardPasswordAuthentication($credential)))->read(new FileBytes(file_get_contents($path)));
$catalog = $document->catalog();
$binary = $catalog->get('FixtureBinary');
$literal = $catalog->get('FixtureLiteral');
$metadata = $catalog->get('Metadata');
$metadata = $metadata === null ? null : $document->resolve($metadata);
$signature = $catalog->get('FixtureSignature');
$signature = $signature === null ? null : $document->resolve($signature);
$content = '';
foreach ((new PageTreeReader($document))->read() as $page) {
    foreach ((new PageContentsReader($document))->read($page) as $stream) {
        $content .= $document->streamData($stream);
    }
}
echo json_encode([
    'revision' => $document->permissions()?->revision,
    'access' => $document->permissions()?->access->name,
    'binarySha256' => $binary instanceof StringType ? hash('sha256', $binary->value) : null,
    'literal' => $literal instanceof StringType ? $literal->value : null,
    'contentSha256' => hash('sha256', $content),
    'metadata' => $metadata instanceof EncodedStream ? $document->streamData($metadata) : null,
    'signature' => $signature instanceof DictionaryType && $signature->get('Contents') instanceof StringType ? bin2hex($signature->get('Contents')->value) : null,
], JSON_THROW_ON_ERROR);
