<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;

require dirname(__DIR__) . '/vendor/autoload.php';
$path = $argv[1] ?? throw new InvalidArgumentException('Choose an explicit fixture.');
$bytes = file_get_contents($path);
if ($bytes === false) {
    throw new RuntimeException('Cannot read the supplied fixture.');
}
$document = (new DocumentReader())->read(new FileBytes($bytes));
$value = $document->catalog()->get('Payload');
$stream = $value === null ? null : $document->resolve($value);
if (!$stream instanceof EncodedStream) {
    throw new RuntimeException('The fixture requires a Payload stream.');
}
echo json_encode(['decoded' => base64_encode($document->streamData($stream)),
    'encoded' => base64_encode($stream->encodedBytes()), 'authenticated' => $document->permissions() !== null], JSON_THROW_ON_ERROR) . "\n";
