<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\ImageCodecs\AutomaticDecoder;
use PDF\Reading\ImageCodecs\CodecRequest;
use PDF\Reading\ImageCodecs\DecodeContext;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\ImageCodecs\Decoder as ImageDecoder;
use PDF\Reading\ImageCodecs\ImageLimits;
use PDF\Reading\Filters\Ascii85Decoder;
use PDF\Reading\Filters\AsciiHexDecoder;
use PDF\Reading\Filters\CcittFaxDecoder;
use PDF\Reading\Filters\CcittFaxParameters;
use PDF\Reading\Filters\DecodedBytes;
use PDF\Reading\Filters\FlateDecoder;
use PDF\Reading\Filters\IdentityDecoder;
use PDF\Reading\Filters\LzwDecoder;
use PDF\Reading\Filters\PredictorDecoder;
use PDF\Reading\Filters\PredictorParameters;
use PDF\Reading\Filters\RunLengthDecoder;
use PDF\Reading\Security\CryptDecodeParameters;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Explicit stream decoding; encoded stream objects retain their original bytes. */
final readonly class StreamDecoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits(), private ?ExternalStreamSource $externalStreams = null, private ?ImageDecoder $imageDecoder = null, private ImageLimits $imageLimits = new ImageLimits(), private bool $allowImageCodecs = true)
    {
    }

    public function decode(EncodedStream $stream, ReadBudget $budget, ?ObjectResolver $resolver = null): string
    {
        $result = $this->decodeResult($stream, $budget, $resolver);
        return $result instanceof DecodedImage ? $this->imageBytes($result) : $result;
    }

    public function imageBytes(DecodedImage $image): string
    {
        return $image->streamBytes($this->limits->maxDecodedStreamBytes, $this->imageLimits);
    }

    /** @internal Preserve image metadata for ReadDocument's shared decoding cache. */
    public function decodeResult(EncodedStream $stream, ReadBudget $budget, ?ObjectResolver $resolver = null, ?DecodeContext $context = null): string|DecodedImage
    {
        $context ??= new DecodeContext($this->limits->maxDepth);
        $context->enter($stream);
        try {
            return $this->filtered($stream, $budget, $resolver, $context);
        } finally {
            $context->leave($stream);
        }
    }

    private function filtered(EncodedStream $stream, ReadBudget $budget, ?ObjectResolver $resolver, DecodeContext $context): string|DecodedImage
    {
        $input = StreamInput::from($stream, $this->limits, $budget, $resolver, $this->externalStreams);
        $filter = $input->filter;
        $parameters = $input->parameters;
        $bytes = $input->bytes;
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
            if (in_array($name->value, ['DCTDecode', 'JPXDecode', 'JBIG2Decode'], true)) {
                if (!$this->allowImageCodecs) {
                    throw new UnsupportedPdfFeature('Image codecs are unavailable during structural PDF parsing.');
                }
                $globals = null;
                $transform = null;
                if ($name->value === 'DCTDecode' && $parameter instanceof DictionaryType) {
                    $value = $this->resolved($parameter->get('ColorTransform'), $resolver);
                    if ($value !== null && !$value instanceof NullType) {
                        if (!$value instanceof IntegerType || !in_array($value->integer(), [0, 1], true)) {
                            throw new ParseException('DCT ColorTransform must be zero or one.');
                        }
                        $transform = $value->integer();
                    }
                }
                if ($name->value === 'JBIG2Decode' && $parameter instanceof DictionaryType) {
                    $globalStream = $this->resolved($parameter->get('JBIG2Globals'), $resolver);
                    if ($globalStream !== null && !$globalStream instanceof NullType) {
                        if (!$globalStream instanceof EncodedStream) {
                            throw new ParseException('JBIG2Globals must resolve to a stream.');
                        }
                        $globalResult = $resolver instanceof ReadDocument
                            ? $resolver->streamData($globalStream)
                            : $this->decodeResult($globalStream, $budget, $resolver, $context);
                        $globals = $globalResult instanceof DecodedImage ? $this->imageBytes($globalResult) : $globalResult;
                    }
                }
                $maximum = min($this->limits->maxDecodedStreamBytes, $budget->remainingDecodedBytes());
                if ($maximum < 1) {
                    throw new ImageCodecs\CodecLimit('The cumulative decoded-byte budget is exhausted.');
                }
                $image = ($this->imageDecoder ?? new AutomaticDecoder())->decode(new CodecRequest(
                    $name->value,
                    $bytes,
                    $this->limits->maxFileBytes,
                    $maximum,
                    $this->limits->maxDepth,
                    $this->imageLimits,
                    $transform,
                    $globals,
                ));
                if ($image->byteLength() > $maximum) {
                    throw new ImageCodecs\CodecLimit('The image decoder returned more samples than its byte limit.');
                }
                $budget->decoded($image->byteLength());
                if ($index === count($filters) - 1) {
                    return $image;
                }
                $bytes = $this->imageBytes($image);
                continue;
            }
            $decoder = match ($name->value) {
                'FlateDecode' => new FlateDecoder($this->limits),
                'LZWDecode' => new LzwDecoder($this->limits, $this->earlyChange($parameter, $resolver)),
                'ASCIIHexDecode' => new AsciiHexDecoder($this->limits),
                'ASCII85Decode' => new Ascii85Decoder($this->limits),
                'RunLengthDecode' => new RunLengthDecoder($this->limits),
                'Crypt' => $this->identity($parameter, $resolver, $index),
                'CCITTFaxDecode' => new CcittFaxDecoder($this->limits, $this->faxParameters($parameter, $resolver)),
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

    private function identity(?BasicType $parameters, ?ObjectResolver $resolver, int $index): IdentityDecoder
    {
        if ($index !== 0) {
            throw new ParseException('Crypt must occur exactly once at the beginning of a stream filter chain.');
        }
        $settings = new CryptDecodeParameters($parameters instanceof DictionaryType ? $parameters : null, $resolver);
        if ($settings->name !== 'Identity') {
            throw new UnsupportedPdfFeature('A non-Identity Crypt filter requires its authenticated security handler.');
        }
        return new IdentityDecoder($this->limits);
    }

    private function faxParameters(?BasicType $parameters, ?ObjectResolver $resolver): CcittFaxParameters
    {
        $dictionary = $parameters instanceof DictionaryType ? $parameters : new DictionaryType();
        return new CcittFaxParameters(
            k: $this->integerParameter($dictionary, 'K', 0, $resolver),
            columns: $this->integerParameter($dictionary, 'Columns', 1728, $resolver),
            rows: $this->integerParameter($dictionary, 'Rows', 0, $resolver),
            endOfLine: $this->booleanParameter($dictionary, 'EndOfLine', false, $resolver),
            encodedByteAlign: $this->booleanParameter($dictionary, 'EncodedByteAlign', false, $resolver),
            endOfBlock: $this->booleanParameter($dictionary, 'EndOfBlock', true, $resolver),
            blackIs1: $this->booleanParameter($dictionary, 'BlackIs1', false, $resolver),
            damagedRowsBeforeError: $this->integerParameter($dictionary, 'DamagedRowsBeforeError', 0, $resolver),
        );
    }

    private function booleanParameter(DictionaryType $dictionary, string $key, bool $default, ?ObjectResolver $resolver): bool
    {
        $value = $this->resolved($dictionary->get($key), $resolver);
        if ($value === null || $value instanceof NullType) {
            return $default;
        }
        if (!$value instanceof BooleanType) {
            throw new ParseException('Stream parameter ' . $key . ' must be boolean.');
        }
        return $value->value;
    }
}
