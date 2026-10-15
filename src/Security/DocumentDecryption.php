<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\EncodedStream;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Authenticated file-local transformation; object-stream members are already decrypted as bytes. */
final readonly class DocumentDecryption
{
    /** @param array<string, CryptFilterDecryption> $filters */
    public function __construct(
        public ReadPermissions $permissions,
        private bool $encryptMetadata,
        private ?ObjectIdentifier $encryptionObject,
        private array $filters,
        private string $strings,
        private string $streams,
        private string $embeddedFiles,
        private ?ObjectReference $documentRoot = null,
        public ?PdfMacLocation $mac = null,
    ) {
        foreach ([$strings, $streams, $embeddedFiles] as $name) {
            $this->filter($name);
        }
    }

    public function object(BasicType $value, ObjectIdentifier $identifier, ObjectResolver $resolver, ReadBudget $budget): BasicType
    {
        if (($this->encryptionObject !== null && (string) $this->encryptionObject === (string) $identifier)
            || ($value instanceof EncodedStream && $value->dictionary()->get('Type') instanceof NameType
                && $value->dictionary()->get('Type')->value === 'XRef')) {
            return $value;
        }
        if ($value instanceof EncodedStream) {
            $dictionary = $this->dictionary($value->dictionary(), $identifier, $budget);
            $type = $dictionary->get('Type');
            $type = $type === null ? null : $resolver->resolve($type);
            $selected = $type instanceof NameType && $type->value === 'EmbeddedFile' ? $this->embeddedFiles : $this->streams;
            [$dictionary, $explicit] = $this->streamFilters($dictionary, $resolver);
            if ($explicit !== null) {
                $selected = $explicit;
            }
            $external = $dictionary->get('F');
            $external = $external === null ? null : $resolver->resolve($external);
            if ($external !== null && !$external instanceof NullType && !$external instanceof StringType && !$external instanceof DictionaryType) {
                throw new ParseException('An external stream F entry must resolve to a file specification.');
            }
            $exempt = (!$this->encryptMetadata && $type instanceof NameType && $type->value === 'Metadata'
                    && $this->isDocumentMetadata($identifier, $resolver))
                || ($external !== null && !$external instanceof NullType);
            if ($exempt && $explicit !== null && $selected !== 'Identity') {
                throw new ParseException('An exempt stream cannot select an encrypting Crypt filter.');
            }
            $bytes = $exempt
                ? $value->encodedBytes() : $this->filter($selected)->decrypt($value->encodedBytes(), $identifier, $explicit !== null);
            $budget->retained(strlen($bytes));
            $dictionary = $dictionary->with(new Entry(new NameType('Length'), new IntegerType(strlen($bytes))));
            return new EncodedStream($dictionary, $bytes, $value->encodedBytes());
        }
        return $this->value($value, $identifier, $budget);
    }

    private function value(BasicType $value, ObjectIdentifier $identifier, ReadBudget $budget): BasicType
    {
        if ($value instanceof StringType) {
            $bytes = $this->filter($this->strings)->decrypt($value->value, $identifier);
            $budget->retained(strlen($bytes));
            return new HexadecimalString($bytes);
        }
        if ($value instanceof ArrayType) {
            return new ArrayType(...array_map(fn (BasicType $item): BasicType => $this->value($item, $identifier, $budget), $value->items));
        }
        return $value instanceof DictionaryType ? $this->dictionary($value, $identifier, $budget) : $value;
    }

    private function dictionary(DictionaryType $dictionary, ObjectIdentifier $identifier, ReadBudget $budget): DictionaryType
    {
        $type = $dictionary->get('Type');
        $signature = ($type instanceof NameType && in_array($type->value, ['Sig', 'DocTimeStamp'], true))
            || ($type === null && $dictionary->get('ByteRange') instanceof ArrayType && $dictionary->get('SubFilter') instanceof NameType);
        $entries = [];
        foreach ($dictionary->entries() as $entry) {
            $entries[] = new Entry($entry->key, $signature && $entry->key->value === 'Contents'
                ? $entry->value : $this->value($entry->value, $identifier, $budget));
        }
        return new DictionaryType(...$entries);
    }

    /** @return array{DictionaryType, ?string} */
    private function streamFilters(DictionaryType $dictionary, ObjectResolver $resolver): array
    {
        $filter = $dictionary->get('Filter');
        $filter = $filter === null ? null : $resolver->resolve($filter);
        $filters = $filter instanceof ArrayType ? $filter->items : ($filter === null || $filter instanceof NullType ? [] : [$filter]);
        $parameters = $dictionary->get('DecodeParms');
        $parameters = $parameters === null ? null : $resolver->resolve($parameters);
        $items = $parameters instanceof ArrayType ? $parameters->items : ($parameters === null || $parameters instanceof NullType ? [] : [$parameters]);
        $selected = null;
        foreach ($filters as $index => $item) {
            $item = $resolver->resolve($item);
            if (!$item instanceof NameType) {
                throw new ParseException('Every encrypted stream filter must resolve to a name.');
            }
            if ($item->value !== 'Crypt') {
                continue;
            }
            if ($index !== 0 || $selected !== null) {
                throw new ParseException('Crypt must occur exactly once at the beginning of a stream filter chain.');
            }
            if ($filter instanceof ArrayType && $parameters !== null && !$parameters instanceof NullType
                && (!$parameters instanceof ArrayType || count($items) !== count($filters))) {
                throw new ParseException('Crypt filter parameters must match their filter array.');
            }
            if ($filter instanceof NameType && $parameters instanceof ArrayType) {
                throw new ParseException('A single Crypt filter requires dictionary parameters.');
            }
            $parameter = isset($items[0]) ? $resolver->resolve($items[0]) : null;
            if ($parameter !== null && !$parameter instanceof NullType && !$parameter instanceof DictionaryType) {
                throw new ParseException('Crypt DecodeParms must resolve to a dictionary or null.');
            }
            $name = $parameter instanceof DictionaryType ? $parameter->get('Name') : null;
            $name = $name === null ? null : $resolver->resolve($name);
            if ($name !== null && !$name instanceof NullType && !$name instanceof NameType) {
                throw new ParseException('A Crypt filter Name must resolve to a name.');
            }
            $selected = $name instanceof NameType ? $name->value : 'Identity';
            $this->filter($selected);
        }
        if ($selected === null) {
            return [$dictionary, null];
        }
        $entries = [];
        foreach ($dictionary->entries() as $entry) {
            if (!in_array($entry->key->value, ['Filter', 'DecodeParms'], true)) {
                $entries[] = $entry;
            }
        }
        if (count($filters) > 1) {
            $entries[] = new Entry(new NameType('Filter'), new ArrayType(...array_slice($filters, 1)));
            if ($parameters instanceof ArrayType) {
                $entries[] = new Entry(new NameType('DecodeParms'), new ArrayType(...array_slice($items, 1)));
            }
        }
        return [new DictionaryType(...$entries), $selected];
    }

    private function filter(string $name): CryptFilterDecryption
    {
        return $this->filters[$name] ?? throw new ParseException('A selected crypt filter is not defined in the encryption dictionary.');
    }

    private function isDocumentMetadata(ObjectIdentifier $identifier, ObjectResolver $resolver): bool
    {
        if ($this->documentRoot === null) {
            return false;
        }
        $catalog = $resolver->resolve($this->documentRoot);
        if (!$catalog instanceof DictionaryType) {
            throw new ParseException('The document metadata exemption requires a catalog dictionary.');
        }
        // Compare the actual PDF identity without loading the metadata stream recursively.
        // A /Type /Metadata elsewhere does not make its payload document-level metadata.
        $metadata = $catalog->get('Metadata');
        return $metadata instanceof ObjectReference && (string) $metadata->identifier === (string) $identifier;
    }
}
