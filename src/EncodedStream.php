<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;

/** Original stream bytes and dictionary; parsing alone never executes a codec. */
final readonly class EncodedStream implements BasicType
{
    use SerializesPdf;

    public function __construct(public DictionaryType $dictionary, public string $bytes, private ?string $originalBytes = null)
    {
    }

    public function dictionary(): DictionaryType
    {
        return $this->dictionary;
    }

    public function encodedBytes(): string
    {
        return $this->bytes;
    }

    /** The source ciphertext when authenticated, otherwise the original encoded bytes. */
    public function originalEncodedBytes(): string
    {
        return $this->originalBytes ?? $this->bytes;
    }

    public function toPdf(SerializationContext $context): string
    {
        throw new \LogicException('External streams require explicit import before serialization.');
    }
}
