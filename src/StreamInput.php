<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** ISO 32000-2 Table 5: external data uses FFilter/FDecodeParms and ignores local bytes. */
final readonly class StreamInput
{
    public function __construct(public string $bytes, public ?BasicType $filter, public ?BasicType $parameters)
    {
    }

    public static function from(
        EncodedStream $stream,
        ReaderLimits $limits,
        ReadBudget $budget,
        ?ObjectResolver $resolver,
        ?ExternalStreamSource $external,
    ): self {
        $dictionary = $stream->dictionary();
        $file = self::resolved($dictionary->get('F'), $resolver);
        if ($file !== null) {
            if ($external === null) {
                throw new UnsupportedPdfFeature('External stream data requires an explicit application-supplied source.');
            }
            if (!$file instanceof StringType && !$file instanceof DictionaryType) {
                throw new ParseException('An external stream F entry must resolve to a file specification.');
            }
            $filter = self::resolved($dictionary->get('FFilter'), $resolver);
            $parameters = self::resolved($dictionary->get('FDecodeParms'), $resolver);
            $budget->externalRequest();
            $source = $external->read(new ExternalStreamRequest($file, $limits->maxExternalStreamBytes, $resolver));
            if (strlen($source->bytes) > $limits->maxExternalStreamBytes) {
                throw new ParseException('External stream bytes exceed the configured acquisition limit.');
            }
            $budget->retained(strlen($source->bytes));
            return new self($source->bytes, $filter, $parameters);
        }
        foreach (['FFilter', 'FDecodeParms'] as $key) {
            if (self::resolved($dictionary->get($key), $resolver) !== null) {
                throw new ParseException('External stream filters require an F file specification.');
            }
        }
        return new self(
            $stream->encodedBytes(),
            self::resolved($dictionary->get('Filter'), $resolver),
            self::resolved($dictionary->get('DecodeParms'), $resolver)
        );
    }

    private static function resolved(?BasicType $value, ?ObjectResolver $resolver): ?BasicType
    {
        if ($value instanceof ObjectReference && $resolver === null) {
            throw new UnsupportedPdfFeature('An indirect stream parameter requires an object resolver.');
        }
        $value = $value === null ? null : ($resolver?->resolve($value) ?? $value);
        return $value instanceof NullType ? null : $value;
    }
}
