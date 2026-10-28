<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ObjectReference;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ParseException;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** ISO 32000-2 Table 14: private fields remain available to the security handler. */
final readonly class CryptDecodeParameters
{
    public string $name;

    public function __construct(?DictionaryType $dictionary = null, ?ObjectResolver $resolver = null)
    {
        $type = $this->resolved($dictionary?->get('Type'), $resolver);
        if ($type !== null && (!$type instanceof NameType || $type->value !== 'CryptFilterDecodeParms')) {
            throw new ParseException('Crypt DecodeParms Type must be CryptFilterDecodeParms when present.');
        }
        $name = $this->resolved($dictionary?->get('Name'), $resolver);
        if ($name !== null && !$name instanceof NameType) {
            throw new ParseException('A Crypt filter Name must resolve to a name.');
        }
        $this->name = $name instanceof NameType ? $name->value : 'Identity';
    }

    private function resolved(?BasicType $value, ?ObjectResolver $resolver): ?BasicType
    {
        if ($value instanceof ObjectReference && $resolver === null) {
            throw new UnsupportedPdfFeature('Indirect Crypt parameters require the owning document resolver.');
        }
        $value = $value === null ? null : ($resolver?->resolve($value) ?? $value);
        return $value instanceof NullType ? null : $value;
    }
}
