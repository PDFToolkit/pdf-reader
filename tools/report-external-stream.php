<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\ExternalStreamRequest;
use PDF\Reading\ExternalStreamSource;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\StringType;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Only bytes explicitly supplied on this CLI are available; the PDF cannot choose a disk path. */
final class SuppliedOraclePayload implements ExternalStreamSource
{
    public int $requests = 0;

    public function __construct(private readonly FileBytes $bytes)
    {
    }

    public function read(ExternalStreamRequest $request): FileBytes
    {
        ++$this->requests;
        $name = $request->specification instanceof StringType ? $request->specification : $request->field('F');
        if (!$name instanceof StringType || $name->value !== 'payload.bin' || strlen($this->bytes->bytes) > $request->maximumBytes) {
            throw new RuntimeException('Only the explicitly supplied bounded oracle payload is available.');
        }
        return $this->bytes;
    }
}

$path = $argv[1] ?? throw new InvalidArgumentException('Choose a PDF source.');
$payload = $argv[2] ?? throw new InvalidArgumentException('Choose the external encoded payload.');
$bytes = file_get_contents($path);
$external = file_get_contents($payload);
if ($bytes === false || $external === false) {
    throw new RuntimeException('Cannot read the explicitly supplied oracle inputs.');
}
$source = new SuppliedOraclePayload(new FileBytes($external));
$reader = new DocumentReader(authentication: ($argv[3] ?? '') === 'encrypted'
    ? new StandardPasswordAuthentication(new PreparedPassword('user')) : null, externalStreams: $source);
$document = $reader->read(new FileBytes($bytes));
if ($source->requests !== 0) {
    throw new RuntimeException('Graph parsing must not acquire an ordinary external stream.');
}
$stream = $document->resolve($document->catalog()->get('ExternalPayload'));
if (!$stream instanceof EncodedStream) {
    throw new RuntimeException('The oracle requires an external stream object.');
}
$decoded = $document->streamData($stream);
if ($document->streamData($stream) !== $decoded || $source->requests !== 1) {
    throw new RuntimeException('The per-document decoded stream cache must retain the acquired result.');
}
echo json_encode(['sha256' => hash('sha256', $decoded), 'bytes' => strlen($decoded),
    'localHex' => bin2hex($stream->encodedBytes()), 'requests' => $source->requests,
    'authenticated' => $document->permissions() !== null], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
