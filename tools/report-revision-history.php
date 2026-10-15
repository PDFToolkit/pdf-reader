<?php

declare(strict_types=1);

use PDF\Reading\CrossReference\EntryKind;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\StructuralPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

require dirname(__DIR__) . '/vendor/autoload.php';
$path = $argv[1] ?? throw new InvalidArgumentException('Choose an explicit PDF source.');
$bytes = file_get_contents($path);
if ($bytes === false) {
    throw new RuntimeException('Cannot read the supplied fixture.');
}
$policy = ($argv[3] ?? '') === 'pdf20' ? StructuralPolicy::Pdf20 : StructuralPolicy::Compatible;
$history = (new DocumentReader(structuralPolicy: $policy))->readRevisionHistory(new FileBytes($bytes));
$reader = new DocumentReader(authentication: ($argv[2] ?? '') === 'password'
    ? new StandardPasswordAuthentication(new PreparedPassword('user')) : null, structuralPolicy: $policy);
$report = [];
foreach ($history as $revision) {
    $raw = $revision->rawDocument();
    $objects = [];
    $signatures = [];
    foreach ($raw->crossReferences()->entries() as $entry) {
        $identifier = $entry->kind === EntryKind::Uncompressed
            ? new ObjectIdentifier(new ObjectNumber($entry->number), new GenerationNumber($entry->generation())) : null;
        $span = $identifier === null ? null : $raw->objectSpan($identifier);
        $objects[] = ['number' => $entry->number, 'generation' => $entry->generation(), 'kind' => $entry->kind->name,
            'first' => $entry->firstField, 'second' => $entry->secondField,
            'span' => $span === null ? null : [$span->start, $span->end]];
        if ($span === null || $identifier === null) {
            continue;
        }
        $value = $raw->object($identifier);
        if (!$value instanceof DictionaryType || !$value->has('ByteRange')) {
            continue;
        }
        $range = $value->get('ByteRange');
        $contents = $value->get('Contents');
        if (!$range instanceof ArrayType || !$contents instanceof StringType) {
            throw new RuntimeException('The fixture signature has no direct source range and Contents.');
        }
        $token = $raw->sourceRevision()->span($contents);
        $signatures[] = ['number' => $entry->number, 'generation' => $entry->generation(),
            'range' => array_map(static fn (IntegerType $item): int => $item->integer(), $range->items),
            'contentsSha256' => hash('sha256', $contents->value), 'span' => $token === null ? null : [$token->start, $token->end]];
    }
    // This is deliberately a separate authenticated read, not a property of the raw history.
    $authenticated = $reader->read($revision->source());
    $marker = $authenticated->catalog()->get('HistoryMarker');
    $compressed = $authenticated->catalog()->get('HistoryCompressed');
    $compressed = $compressed === null ? null : $authenticated->resolve($compressed);
    $compressed = $compressed instanceof DictionaryType ? $compressed->get('Value') : null;
    $report[] = ['ordinal' => $revision->ordinal, 'xref' => $revision->crossReference->section->offset->value,
        'stream' => $revision->crossReference->stream, 'hybrid' => $revision->crossReference->auxiliary !== null,
        'footer' => $revision->boundary->footerOffset,
        'eof' => $revision->boundary->eofOffset, 'minimumEnd' => $revision->boundary->eofMarkerEnd,
        'end' => $revision->boundary->endOffset, 'maximumEnd' => $revision->boundary->maximumEndOffset,
        'sourceSha256' => hash('sha256', $revision->source()->bytes), 'objects' => $objects, 'signatures' => $signatures,
        'rawAuthenticated' => $raw->permissions() !== null, 'rawMac' => $raw->mac()?->value,
        'marker' => $marker instanceof StringType ? $marker->value : null,
        'compressed' => $compressed instanceof StringType ? $compressed->value : null];
}
echo json_encode(['sourceSha256' => hash('sha256', $bytes), 'revisions' => $report], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
