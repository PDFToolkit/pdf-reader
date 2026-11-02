<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Reading\CrossReference\EntryKind;
use PDF\Reading\ObjectReference;
use PDF\Reading\ReadDocument;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** Document-scoped identities, bounded reference queue and non-throwing inspection access. */
final class InspectionAccess
{
    /** @var array<string, ObjectReference> */
    private array $references = [];
    /** @var list<ObjectReference> */
    private array $referenceQueue = [];
    /** @var \SplObjectStorage<BasicType, string> */
    private \SplObjectStorage $direct;
    private int $nextDirect = 0;
    private TextStringDecoder $textDecoder;

    public function __construct(public readonly ReadDocument $document, public readonly InspectionBudget $budget)
    {
        $this->direct = new \SplObjectStorage();
        $version = $this->name($document->catalog()->get('Version'));
        $this->textDecoder = new TextStringDecoder($version === '2.0' ? PdfVersion::PDF20 : $document->header()->version);
    }

    /** @return array{number: string, generation: int}|null */
    public function reference(?BasicType $value): ?array
    {
        return $value instanceof ObjectReference ? ['number' => (string) $value->identifier->objectNumber->value, 'generation' => $value->identifier->generationNumber->value] : null;
    }

    public function identity(BasicType $value): string
    {
        if ($value instanceof ObjectReference) {
            return 'obj-' . $value->identifier->objectNumber->value . '-' . $value->identifier->generationNumber->value;
        }
        if (!$this->direct->contains($value)) {
            $this->direct[$value] = 'direct-' . ++$this->nextDirect;
        }
        return $this->direct[$value];
    }

    public function queue(ObjectReference $reference): bool
    {
        $id = $this->identity($reference);
        if (isset($this->references[$id])) {
            return true;
        }
        if (!$this->budget->reserve('objects') || !$this->budget->reserve('bytes', 256)) {
            return false;
        }
        $this->references[$id] = $reference;
        $this->referenceQueue[] = $reference;
        return true;
    }

    public function queued(int $index): ?ObjectReference
    {
        return $this->referenceQueue[$index] ?? null;
    }

    public function missing(ObjectReference $reference): bool
    {
        $entry = $this->document->crossReferences()->entry($reference->identifier->objectNumber->value);
        return $entry === null || $entry->kind === EntryKind::Free || $entry->kind === EntryKind::Unavailable || $entry->generation() !== $reference->identifier->generationNumber->value;
    }

    public function resolved(?BasicType $value, ?string $nodeId = null): ?BasicType
    {
        if ($value === null) {
            return null;
        }
        try {
            if ($value instanceof ObjectReference && $this->missing($value)) {
                $this->budget->diagnostic('missing-reference', 'The reference is missing, free, unavailable or has a different generation.', $nodeId, $this->reference($value));
                return null;
            }
            return $this->document->resolve($value);
        } catch (\PDF\Reading\ParseException | \PDF\Reading\UnsupportedPdfFeature | \InvalidArgumentException $exception) {
            $this->budget->diagnostic('unreadable-reference', 'The source value could not be resolved within the reader constraints.', $nodeId, $this->reference($value));
            return null;
        }
    }

    public function dictionary(?BasicType $value): ?DictionaryType
    {
        $value = $this->resolved($value);
        return $value instanceof DictionaryType ? $value : null;
    }

    public function name(?BasicType $value): ?string
    {
        $value = $this->resolved($value);
        return $value instanceof NameType ? $value->value : null;
    }

    /** @return list<BasicType> */
    public function items(?BasicType $value): array
    {
        $value = $this->resolved($value);
        return $value instanceof ArrayType ? $value->items : ($value === null || $value instanceof NullType ? [] : [$value]);
    }

    public function text(?BasicType $value): ?string
    {
        $value = $this->resolved($value);
        if (!$value instanceof StringType) {
            return null;
        }
        if (strlen($value->value) > $this->budget->limits->maxStringBytes) {
            $this->budget->limited('limit-string', 'A source byte string exceeds the inspection preview limit.');
            return null;
        }
        if (!$this->budget->reserve('bytes', 128 + strlen($value->value) * 12)) {
            return null;
        }
        return $this->decodeText($value);
    }

    public function decodeText(StringType $value): ?string
    {
        if (str_starts_with($value->value, "\xfe\xff") && !function_exists('iconv')) {
            return null;
        }
        try {
            return $this->textDecoder->decode($value);
        } catch (\InvalidArgumentException $exception) {
            return null;
        }
    }
}
