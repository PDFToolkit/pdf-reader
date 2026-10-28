<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Reading\EncodedStream;
use PDF\Reading\ExternalStreamSource;
use PDF\Reading\ObjectReference;
use PDF\Reading\ReadDocument;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** One atomic copy from one source document. Call once with every root whose identity must be shared. */
final class ImportSession
{
    private bool $used = false;
    private readonly ImportBudget $budget;
    private readonly ImportGuard $guard;
    private readonly ImportStreams $streams;
    /** @var array<string, IndirectReference> */
    private array $references = [];
    /** @var \SplObjectStorage<BasicType, BasicType> */
    private \SplObjectStorage $copies;
    /** @var \SplObjectStorage<BasicType, true> */
    private \SplObjectStorage $building;

    public function __construct(
        private readonly ReadDocument $document,
        StreamImportMode $streams,
        private readonly ImportLimits $limits = new ImportLimits(),
        ?ExternalStreamSource $externalStreams = null,
    ) {
        $this->budget = new ImportBudget($limits);
        $this->guard = new ImportGuard($document);
        $this->streams = new ImportStreams($document, $streams, $limits, $externalStreams);
        $this->copies = new \SplObjectStorage();
        $this->building = new \SplObjectStorage();
    }

    /** Source roots must belong to this session's document. No filesystem access or in-place edit occurs. */
    public function import(BasicType ...$roots): ImportedGraph
    {
        if ($this->used) {
            throw new \LogicException('An import session can be used only once, including after a failed import.');
        }
        $this->used = true;
        if ($roots === []) {
            throw new \InvalidArgumentException('Select at least one source root to import.');
        }
        try {
            $imported = [];
            foreach ($roots as $root) {
                $imported[] = $this->copy($root, 0);
            }
            return new ImportedGraph($imported, $this->references);
        } finally {
            // Failure cannot leave a resumable or publicly accessible half-built graph.
            $this->references = [];
            $this->copies = new \SplObjectStorage();
            $this->building = new \SplObjectStorage();
        }
    }

    private function copy(BasicType $source, int $depth): BasicType
    {
        $this->budget->value($depth);
        if ($source instanceof ObjectReference) {
            return $this->reference($source, $depth);
        }
        if (isset($this->copies[$source])) {
            if (isset($this->building[$this->copies[$source]])) {
                $this->budget->cycle();
            }
            return $this->copies[$source];
        }
        $target = $this->allocate($source);
        $this->copies[$source] = $target;
        $this->building[$target] = true;
        $this->fill($source, $target, $depth);
        unset($this->building[$target]);
        return $target;
    }

    private function reference(ObjectReference $source, int $depth): IndirectReference
    {
        $aliases = [];
        $value = $source;
        while ($value instanceof ObjectReference) {
            $identity = $value->identifier->objectNumber->value . ':' . $value->identifier->generationNumber->value;
            if (isset($this->references[$identity])) {
                $reference = $this->references[$identity];
                if (isset($this->building[$reference->target()])) {
                    $this->budget->cycle();
                }
                foreach ($aliases as $alias) {
                    $this->references[$alias] = $reference;
                }
                return $reference;
            }
            if (isset($aliases[$identity]) || count($aliases) + $depth > $this->limits->maxDepth) {
                throw new ImportException('An import reference chain contains a cycle or exceeds its depth limit.');
            }
            $this->budget->object();
            $aliases[$identity] = $identity;
            $value = $this->document->object($value->identifier);
        }
        if (isset($this->copies[$value])) {
            if (isset($this->building[$this->copies[$value]])) {
                $this->budget->cycle();
            }
            $target = $this->copies[$value];
            $fill = false;
        } else {
            $this->budget->value($depth + count($aliases));
            $target = $this->allocate($value);
            $this->copies[$value] = $target;
            $fill = true;
        }
        $reference = new IndirectReference($target);
        foreach ($aliases as $alias) {
            $this->references[$alias] = $reference;
        }
        if ($fill) {
            $this->building[$target] = true;
            $this->fill($value, $target, $depth + count($aliases));
            unset($this->building[$target]);
        }
        return $reference;
    }

    private function allocate(BasicType $source): BasicType
    {
        if ($source instanceof DictionaryType) {
            $this->guard->dictionary($source);
            return new ImportedDictionary();
        }
        if ($source instanceof EncodedStream) {
            $this->guard->dictionary($source->dictionary());
            return new ImportedStream();
        }
        if ($source instanceof ArrayType) {
            return new ImportedArray();
        }
        if ($source instanceof StringType || $source instanceof NameType) {
            $this->budget->bytes(strlen($source->value));
        }
        return match (true) {
            $source instanceof NameType => new NameType($source->value),
            $source instanceof IntegerType => new IntegerType($source->integer()),
            $source instanceof NumericType => new NumericType($source->value),
            $source instanceof LiteralString => new LiteralString($source->value),
            $source instanceof StringType => new HexadecimalString($source->value),
            $source instanceof BooleanType => new BooleanType($source->value),
            $source instanceof NullType => new NullType(),
            default => throw new ImportException('This value is not a supported source PDF object.'),
        };
    }

    private function fill(BasicType $source, BasicType $target, int $depth): void
    {
        if ($source instanceof DictionaryType && $target instanceof ImportedDictionary) {
            $target->seal(...$this->entries($source, $depth));
        } elseif ($source instanceof ArrayType && $target instanceof ImportedArray) {
            $values = [];
            foreach ($source->items as $item) {
                $values[] = $this->copy($item, $depth + 1);
            }
            $target->seal(...$values);
        } elseif ($source instanceof EncodedStream && $target instanceof ImportedStream) {
            [$bytes, $chain] = $this->streams->read($source);
            $this->budget->bytes(strlen($bytes));
            $dictionary = new DictionaryType(...$this->entries($source->dictionary(), $depth, ['Length', 'Filter', 'DecodeParms', 'F', 'FFilter', 'FDecodeParms']));
            $filters = [];
            foreach ($chain as [$name, $parameter]) {
                $this->budget->bytes(strlen($name->value));
                $native = $parameter === null ? null : $this->copy($parameter, $depth + 1);
                if ($native !== null && !$native instanceof DictionaryType) {
                    throw new \LogicException('Imported filter parameters must be dictionaries.');
                }
                $filters[] = new PreservedFilter(new NameType($name->value), $native);
            }
            $target->seal($bytes, $dictionary, ...$filters);
        }
    }

    /** @param list<string> $skip
     * @return list<Entry>
     */
    private function entries(DictionaryType $source, int $depth, array $skip = []): array
    {
        $entries = [];
        foreach ($source->entries() as $entry) {
            if (in_array($entry->key->value, $skip, true)) {
                continue;
            }
            $this->budget->bytes(strlen($entry->key->value));
            $entries[] = new Entry(new NameType($entry->key->value), $this->copy($entry->value, $depth + 1));
        }
        return $entries;
    }
}
