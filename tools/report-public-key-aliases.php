<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

require dirname(__DIR__) . '/vendor/autoload.php';
$directory = $argv[1] ?? throw new InvalidArgumentException('A fixture directory is required.');
$certificate = file_get_contents($directory . '/recipient.der');
$privateKey = file_get_contents($directory . '/recipient-key.pem');
if ($certificate === false || $privateKey === false) {
    throw new RuntimeException('Cannot read synthetic fixture credentials.');
}
$identity = new OpenSslIdentity(
    new CertificateChain(new X509Certificate(new HexadecimalString($certificate))),
    $privateKey,
);
$reports = [];
foreach (array_slice($argv, 2) as $filename) {
    $bytes = file_get_contents($directory . '/' . $filename);
    if ($bytes === false) {
        throw new RuntimeException('Cannot read source fixture.');
    }
    $reader = new DocumentReader(authentication: new PublicKeyAuthentication(
        new OpenSslRecipientDecrypter($identity),
        PublicKeyPermissionByteOrder::MostSignificantFirst,
    ));
    $document = $reader->read(new FileBytes($bytes));
    $catalog = $document->catalog();
    $metadata = static function (DictionaryType $owner, string $name) use ($document): string {
        $reference = $owner->get($name);
        $stream = $reference === null ? null : $document->resolve($reference);
        if (!$stream instanceof EncodedStream) {
            throw new RuntimeException('An expected metadata stream is missing.');
        }
        return base64_encode($document->streamData($stream));
    };
    $report = [];
    foreach (['Metadata', 'FixturePageMetadata', 'FixtureObjectMetadata'] as $name) {
        $report[$name] = $metadata($catalog, $name);
    }
    foreach (['PageMetadata' => 'FixturePage', 'FormMetadata' => 'FixtureForm'] as $name => $ownerName) {
        $reference = $catalog->get($ownerName);
        $owner = $reference === null ? null : $document->resolve($reference);
        $dictionary = $owner instanceof EncodedStream ? $owner->dictionary() : $owner;
        if (!$dictionary instanceof DictionaryType) {
            throw new RuntimeException('An expected metadata owner is missing.');
        }
        $report[$name] = $metadata($dictionary, 'Metadata');
    }
    $reference = $document->trailer()->get('Encrypt');
    $encryption = $reference === null ? null : $document->resolve($reference);
    $filters = $encryption instanceof DictionaryType ? $encryption->get('CF') : null;
    $filter = $filters instanceof DictionaryType ? $filters->get('DefaultCryptFilter') : null;
    $packets = $filter instanceof DictionaryType ? $filter->get('Recipients') : null;
    if (!$packets instanceof ArrayType) {
        throw new RuntimeException('The terminal encryption dictionary lost its recipient array.');
    }
    $report['recipientPacketsSha256'] = array_map(static function ($packet): string {
        if (!$packet instanceof StringType) {
            throw new RuntimeException('A terminal recipient packet is not a byte string.');
        }
        return hash('sha256', $packet->value);
    }, $packets->items);
    $reports[] = $report;
}
echo json_encode($reports, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
