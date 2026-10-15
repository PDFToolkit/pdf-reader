<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\Filters\Ascii85Decoder;
use PDF\Reading\Filters\AsciiHexDecoder;
use PDF\Reading\Filters\DecodedBytes;
use PDF\Reading\Filters\FlateDecoder;
use PDF\Reading\Filters\LzwDecoder;
use PDF\Reading\Filters\PredictorDecoder;
use PDF\Reading\Filters\PredictorParameters;
use PDF\Reading\Filters\RunLengthDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Explicit stream decoding; encoded stream objects retain their original bytes. */
final readonly class StreamDecoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
    }

    public function decode(EncodedStream $stream, ReadBudget $budget, ?ObjectResolver $resolver = null): string
    {
        $dictionary = $stream->dictionary();
        foreach (['F', 'FFilter', 'FDecodeParms'] as $externalKey) {
            $value = $this->resolved($dictionary->get($externalKey), $resolver);
            if ($value !== null && !$value instanceof NullType) {
                throw new UnsupportedPdfFeature('Decoding external-file streams is not supported.');
            }
        }
        $filter = $this->resolved($dictionary->get('Filter'), $resolver);
        $parameters = $this->resolved($dictionary->get('DecodeParms'), $resolver);
        $bytes = $stream->encodedBytes();
        if ($filter === null || $filter instanceof NullType) {
            if ($parameters !== null && !$parameters instanceof NullType) {
                throw new ParseException('DecodeParms requires a Filter entry.');
            }
            $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
            $output->append($bytes);
            return $output->bytes();
        }
        if (!$filter instanceof NameType && !$filter instanceof ArrayType) {
            throw new ParseException('Filter must be a name or an array of names.');
        }
        $filters = $filter instanceof ArrayType ? $filter->items : [$filter];
        if (count($filters) > $this->limits->maxFilters) {
            throw new ParseException('Stream filter chain exceeds the configured count limit.');
        }
        if ($filter instanceof ArrayType) {
            if ($parameters !== null && !$parameters instanceof NullType && !$parameters instanceof ArrayType) {
                throw new ParseException('A filter array requires a corresponding DecodeParms array.');
            }
            if ($parameters instanceof ArrayType && count($parameters) !== count($filters)) {
                throw new ParseException('Filter and DecodeParms arrays must have equal lengths.');
            }
            $parameterList = $parameters instanceof ArrayType ? $parameters->items : [];
        } else {
            if ($parameters !== null && !$parameters instanceof NullType && !$parameters instanceof DictionaryType) {
                throw new ParseException('A single filter requires a dictionary or null DecodeParms.');
            }
            $parameterList = $parameters === null ? [] : [$parameters];
        }
        if ($filters === []) {
            $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
            $output->append($bytes);
            return $output->bytes();
        }
        foreach ($filters as $index => $item) {
            $name = $this->resolved($item, $resolver);
            $parameter = $this->resolved($parameterList[$index] ?? null, $resolver);
            if (!$name instanceof NameType) {
                throw new ParseException('Every stream filter must resolve to a name.');
            }
            if ($parameter !== null && !$parameter instanceof NullType && !$parameter instanceof DictionaryType) {
                throw new ParseException('Every DecodeParms element must resolve to a dictionary or null.');
            }
            $decoder = match ($name->value) {
                'FlateDecode' => new FlateDecoder($this->limits),
                'LZWDecode' => new LzwDecoder($this->limits, $this->earlyChange($parameter, $resolver)),
                'ASCIIHexDecode' => new AsciiHexDecoder($this->limits),
                'ASCII85Decode' => new Ascii85Decoder($this->limits),
                'RunLengthDecode' => new RunLengthDecoder($this->limits),
                default => throw new UnsupportedPdfFeature('The stream filter is not supported.'),
            };
            $bytes = $decoder->decode($bytes, $budget);
            if (in_array($name->value, ['FlateDecode', 'LZWDecode'], true) && $parameter instanceof DictionaryType) {
                $predictor = $this->integerParameter($parameter, 'Predictor', 1, $resolver);
                // Other predictor parameters have no meaning when prediction is disabled.
                $settings = $predictor === 1 ? new PredictorParameters() : new PredictorParameters(
                    $predictor,
                    $this->integerParameter($parameter, 'Colors', 1, $resolver),
                    $this->integerParameter($parameter, 'BitsPerComponent', 8, $resolver),
                    $this->integerParameter($parameter, 'Columns', 1, $resolver),
                );
                $bytes = (new PredictorDecoder($settings, $this->limits))->decode($bytes, $budget);
            }
        }
        return $bytes;
    }

    private function resolved(?BasicType $value, ?ObjectResolver $resolver): ?BasicType
    {
        if ($value === null) {
            return null;
        }
        if ($resolver !== null) {
            return $resolver->resolve($value);
        }
        if ($value instanceof ObjectReference) {
            throw new UnsupportedPdfFeature('An indirect stream parameter requires an object resolver.');
        }
        return $value;
    }

    private function integerParameter(
        DictionaryType $dictionary,
        string $key,
        int $default,
        ?ObjectResolver $resolver,
    ): int {
        $value = $this->resolved($dictionary->get($key), $resolver);
        if ($value === null || $value instanceof NullType) {
            return $default;
        }
        if (!$value instanceof IntegerType) {
            throw new ParseException('Stream predictor ' . $key . ' must be an integer.');
        }
        return $value->integer();
    }

    private function earlyChange(?BasicType $parameters, ?ObjectResolver $resolver): EarlyChange
    {
        $value = $parameters instanceof DictionaryType ? $this->integerParameter($parameters, 'EarlyChange', 1, $resolver) : 1;
        if ($value !== 0 && $value !== 1) {
            throw new ParseException('LZW EarlyChange must be zero or one.');
        }
        return new EarlyChange(new IntegerType($value));
    }
}
