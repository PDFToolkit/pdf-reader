<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Document\Metadata\XmpUtf8Encoding;
use PDF\Reading\EncodedStream;
use PDF\Reading\ExternalStreamSource;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReadDocument;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\CryptDecodeParameters;
use PDF\Reading\StreamDecoder;
use PDF\Reading\StreamInput;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** @internal Stream acquisition/decoding shares a bounded budget across every imported root. */
final readonly class ImportStreams
{
    private ReaderLimits $limits;
    private ReadBudget $budget;

    public function __construct(private ReadDocument $document, private StreamImportMode $mode, ImportLimits $limits, private ?ExternalStreamSource $external)
    {
        $this->limits = new ReaderLimits(
            maxDepth: $limits->maxDepth,
            maxDecodedStreamBytes: $limits->maxStreamBytes,
            maxTotalDecodedBytes: $limits->maxBytes,
            maxFilters: $limits->maxFilters,
            maxRetainedBytes: $limits->maxBytes,
            maxExternalStreamBytes: $limits->maxStreamBytes,
            maxExternalStreamRequests: $limits->maxExternalRequests,
        );
        $this->budget = new ReadBudget($this->limits);
    }

    /** @return array{string, list<array{NameType, ?DictionaryType}>} */
    public function read(EncodedStream $stream): array
    {
        $input = StreamInput::from($stream, $this->limits, $this->budget, $this->document, $this->external);
        if (strlen($input->bytes) > $this->limits->maxDecodedStreamBytes) {
            throw new ImportException('An imported encoded stream exceeds its byte limit.');
        }
        $filters = $this->filters($input);
        $dictionary = $stream->dictionary();
        $type = $this->resolved($dictionary->get('Type'));
        $subtype = $this->resolved($dictionary->get('Subtype'));
        $metadata = ($type instanceof NameType && $type->value === 'Metadata') || ($subtype instanceof NameType && $subtype->value === 'XML');
        if ($this->mode === StreamImportMode::PreserveEncoded && !$metadata) {
            return [$input->bytes, $filters];
        }
        // The external port has already been called once. Decode this local input, never acquire it again.
        $entries = [];
        if ($input->filter !== null) {
            $entries[] = new Entry(new NameType('Filter'), $input->filter);
        }
        if ($input->parameters !== null) {
            $entries[] = new Entry(new NameType('DecodeParms'), $input->parameters);
        }
        $bytes = (new StreamDecoder($this->limits))->decode(new EncodedStream(new DictionaryType(...$entries), $input->bytes), $this->budget, $this->document);
        if ($metadata) {
            $version = $this->resolved($this->document->catalog()->get('Version'));
            if ($this->document->header()->version->value === '2.0' || ($version instanceof NameType && $version->value === '2.0')) {
                (new XmpUtf8Encoding())->validate($bytes);
            }
        }
        return [$bytes, []];
    }

    /** @return list<array{NameType, ?DictionaryType}> */
    private function filters(StreamInput $input): array
    {
        $filter = $input->filter;
        $parameters = $input->parameters;
        if ($filter === null || $filter instanceof NullType) {
            if ($parameters !== null && !$parameters instanceof NullType) {
                throw new ImportException('Import DecodeParms requires Filter.');
            }
            return [];
        }
        if (!$filter instanceof NameType && !$filter instanceof ArrayType) {
            throw new ImportException('Import Filter must be a name or array.');
        }
        $names = $filter instanceof ArrayType ? $filter->items : [$filter];
        if (count($names) > $this->limits->maxFilters) {
            throw new ImportException('The import filter count was exceeded.');
        }
        if ($filter instanceof ArrayType) {
            if ($parameters !== null && !$parameters instanceof NullType && (!$parameters instanceof ArrayType || count($parameters) !== count($names))) {
                throw new ImportException('Import filter and parameter arrays must have equal lengths.');
            }
            $values = $parameters instanceof ArrayType ? $parameters->items : [];
        } else {
            if ($parameters !== null && !$parameters instanceof NullType && !$parameters instanceof DictionaryType) {
                throw new ImportException('Import single-filter parameters must be a dictionary or null.');
            }
            $values = [$parameters];
        }
        $result = [];
        foreach ($names as $index => $name) {
            $name = $this->resolved($name);
            $parameter = $this->resolved($values[$index] ?? null);
            if (!$name instanceof NameType || ($parameter !== null && !$parameter instanceof DictionaryType)) {
                throw new ImportException('Import filter names or parameters have invalid types.');
            }
            if ($name->value === 'Crypt') {
                if ($index !== 0 || (new CryptDecodeParameters($parameter, $this->document))->name !== 'Identity') {
                    throw new ImportException('Only an initial Identity Crypt filter may remain after source authentication.');
                }
                continue;
            }
            $result[] = [$name, $parameter];
        }
        return $result;
    }

    private function resolved(?BasicType $value): ?BasicType
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof NullType ? null : $value;
    }
}
