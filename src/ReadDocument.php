<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\EntryKind;
use PDF\Reading\CrossReference\Index;
use PDF\Reading\Security\DocumentDecryption;
use PDF\Reading\Security\DocumentPermissions;
use PDF\Reading\Security\PdfMacLocation;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\NullType;

/** A read-only PDF graph with explicit resolution and caches scoped to this document. */
final class ReadDocument implements ObjectIdentityResolver
{
    /** @var array<int, BasicType> */
    private array $objects = [];
    /** @var array<int, true> */
    private array $loading = [];
    /** @var array<int, list<array{number: int, value: BasicType}>> */
    private array $objectStreams = [];
    /** @var array<int, SourceObjectSpan> */
    private array $objectSpans = [];
    /** @var \SplObjectStorage<EncodedStream, string> */
    private \SplObjectStorage $decoded;

    public function __construct(
        private readonly FileBytes $source,
        private readonly FileHeader $fileHeader,
        private readonly DictionaryType $fileTrailer,
        private readonly Index $index,
        private readonly ReaderLimits $limits,
        private readonly ReadBudget $budget,
        private readonly StreamDecoder $decoder,
        private readonly ?DocumentDecryption $decryption = null,
        private readonly ?SourceRevision $physicalRevision = null,
        private readonly ?SourceStringSpans $sourceStrings = null,
        private readonly StructuralPolicy $structuralPolicy = StructuralPolicy::Compatible,
    ) {
        $this->decoded = new \SplObjectStorage();
    }

    public function header(): FileHeader
    {
        return $this->fileHeader;
    }

    public function trailer(): DictionaryType
    {
        return $this->fileTrailer;
    }

    public function permissions(): ?DocumentPermissions
    {
        return $this->decryption?->permissions;
    }

    public function sourceRevision(): SourceRevision
    {
        return $this->physicalRevision ?? throw new UnsupportedPdfFeature('This read graph has no physical source-revision provenance.');
    }

    public function crossReferences(): Index
    {
        return $this->index;
    }

    /** Compressed, missing, free and different-generation objects have no ordinary source span. */
    public function objectSpan(ObjectIdentifier $identifier): ?SourceObjectSpan
    {
        $entry = $this->index->entry($identifier->objectNumber->value);
        if ($entry === null || $entry->kind !== EntryKind::Uncompressed || $entry->generation() !== $identifier->generationNumber->value) {
            return null;
        }
        $this->object($identifier);
        $span = $this->objectSpans[$identifier->objectNumber->value] ?? null;
        return $span !== null && (string) $span->identifier === (string) $identifier ? $span : null;
    }

    public function mac(): ?PdfMacLocation
    {
        return $this->decryption?->mac;
    }

    public function catalog(): DictionaryType
    {
        $reference = $this->fileTrailer->get('Root');
        if (!$reference instanceof ObjectReference) {
            throw new ParseException('The effective trailer requires an indirect Root.');
        }
        $catalog = $this->resolve($reference);
        if (!$catalog instanceof DictionaryType || !(new DictionaryAccess($catalog))->hasName('Type', 'Catalog') || !$catalog->get('Pages') instanceof ObjectReference) {
            throw new ParseException('Root must resolve to a catalog with an indirect page tree.');
        }
        $version = $catalog->get('Version');
        $version = $version === null ? null : $this->resolve($version);
        if ($version !== null && !$version instanceof NullType && (!$version instanceof \PDF\Syntax\Object\NameType || !preg_match('/\A(?:1\.[0-7]|2\.0)\z/', $version->value))) {
            throw new UnsupportedPdfFeature('The catalog requires a supported PDF 1.0–1.7 or 2.0 version.');
        }
        return $catalog;
    }

    public function resolve(BasicType $value): BasicType
    {
        return $this->resolveWithIdentity($value)->value;
    }

    /** @internal Preserve terminal identity when a security exemption belongs to one actual object. */
    public function resolveWithIdentity(BasicType $value): ResolvedValue
    {
        return $this->follow($value);
    }

    public function refersTo(ObjectReference $reference, ObjectIdentifier $identifier): bool
    {
        $result = $this->follow($reference, $identifier);
        return $result->terminalId !== null && (string) $result->terminalId === (string) $identifier;
    }

    private function follow(BasicType $value, ?ObjectIdentifier $stop = null): ResolvedValue
    {
        $seen = [];
        $terminal = null;
        while ($value instanceof ObjectReference) {
            $identity = $value->identifier->objectNumber->value . ':' . $value->identifier->generationNumber->value;
            if (isset($seen[$identity]) || count($seen) >= $this->limits->maxDepth) {
                throw new ParseException('An indirect-reference chain contains a cycle or exceeds its depth limit.');
            }
            $seen[$identity] = true;
            $terminal = $value->identifier;
            // The caller is processing this actual stream already. Loading it
            // again would recurse; identity, including generation, is sufficient.
            if ($stop !== null && (string) $terminal === (string) $stop) {
                return new ResolvedValue($value, $terminal);
            }
            $value = $this->object($value->identifier);
        }
        return new ResolvedValue($value, $terminal);
    }

    public function object(ObjectIdentifier $identifier): BasicType
    {
        $number = $identifier->objectNumber->value;
        $entry = $this->index->entry($number);
        if ($entry === null || $entry->kind === EntryKind::Free || $entry->kind === EntryKind::Unavailable || $entry->generation() !== $identifier->generationNumber->value) {
            return new NullType();
        }
        if (isset($this->objects[$number])) {
            return $this->objects[$number];
        }
        if (isset($this->loading[$number]) || count($this->loading) >= $this->limits->maxDepth || count($this->objects) >= $this->limits->maxObjects) {
            throw new ParseException('Indirect-object loading contains a cycle or exceeds its limits.');
        }
        $this->loading[$number] = true;
        try {
            if ($entry->kind === EntryKind::Compressed) {
                $value = $this->compressed($entry->firstField, $entry->secondField, $number);
            } else {
                $physical = (new IndirectObjectReader($this->source, $this->limits, $this->budget, $this->sourceStrings, $this->structuralPolicy))->readPhysical($entry->firstField, $identifier, $this);
                $value = $physical->object->value;
                $this->budget->retained(64);
                $this->objectSpans[$number] = $physical->span;
                if ($value instanceof EncodedStream && (new DictionaryAccess($value->dictionary()))->hasName('Type', 'ObjStm')) {
                    $length = $value->dictionary()->get('Length');
                    if ($identifier->generationNumber->value !== 0 || ($length instanceof ObjectReference && $this->index->entry($length->identifier->objectNumber->value)?->kind === EntryKind::Compressed)) {
                        throw new ParseException('An object stream must have generation zero and an uncompressed Length object.');
                    }
                }
                if ($this->decryption !== null) {
                    $value = $this->decryption->object($value, $identifier, $this, $this->budget);
                }
            }
            $this->objects[$number] = $value;
            return $value;
        } finally {
            unset($this->loading[$number]);
        }
    }

    private function compressed(int $container, int $ordinal, int $number): BasicType
    {
        $containerEntry = $this->index->entry($container);
        if ($containerEntry === null || $containerEntry->kind !== EntryKind::Uncompressed || $containerEntry->generation() !== 0) {
            throw new ParseException('An object-stream container must be an uncompressed generation-zero object.');
        }
        if (!isset($this->objectStreams[$container])) {
            $stream = $this->object(new ObjectIdentifier(new ObjectNumber($container), new GenerationNumber()));
            if (!$stream instanceof EncodedStream) {
                throw new ParseException('An object-stream container must be a stream.');
            }
            $this->objectStreams[$container] = (new ObjectStreamReader($this->limits, $this->budget, $this->structuralPolicy))->read($stream, $this->streamData($stream), $this);
        }
        $member = $this->objectStreams[$container][$ordinal] ?? null;
        if ($member === null || $member['number'] !== $number) {
            throw new ParseException('A compressed cross-reference index does not identify the expected member.');
        }
        return $member['value'];
    }

    public function streamData(EncodedStream $stream): string
    {
        if (!$this->decoded->offsetExists($stream)) {
            $this->decoded[$stream] = $this->decoder->decode($stream, $this->budget, $this);
        }
        return $this->decoded[$stream];
    }
}
