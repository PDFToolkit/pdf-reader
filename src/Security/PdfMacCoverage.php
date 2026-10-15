<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;
use PDF\Reading\SourceRevision;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Prove the single excluded token's lexical identity before hashing the original ciphertext. */
final readonly class PdfMacCoverage
{
    public function __construct(private SourceRevision $revision)
    {
    }

    public function coveredBytes(DictionaryType $dictionary, string $entry, bool $strictHexadecimal): string
    {
        $range = $dictionary->get('ByteRange');
        $value = $dictionary->get($entry);
        if (!$range instanceof ArrayType || count($range) !== 4 || !$value instanceof HexadecimalString) {
            throw new ParseException('PDF MAC requires a direct four-integer ByteRange and a direct hexadecimal payload.');
        }
        $numbers = [];
        foreach ($range->items as $number) {
            if (!$number instanceof IntegerType || $number->integer() < 0) {
                throw new ParseException('PDF MAC ByteRange entries must be direct nonnegative integers.');
            }
            $numbers[] = $number->integer();
        }
        [$first, $length, $second, $tail] = $numbers;
        $source = $this->revision->source->bytes;
        $size = strlen($source);
        $span = $this->revision->span($value);
        if ($first !== 0 || $length >= $second || $second > $size || $tail !== $size - $second
            || $span === null || $span->start !== $length || $span->end !== $second) {
            throw new ParseException('PDF MAC ByteRange must cover the whole current file except its actual payload token.');
        }
        if ($strictHexadecimal) {
            $hex = substr($source, $length + 1, $second - $length - 2);
            if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)
                || !hash_equals($value->value, (string) hex2bin($hex))) {
                throw new ParseException('A standalone PDF MAC token permits only complete hexadecimal bytes without whitespace or padding.');
            }
        }
        return $this->revision->excluding($span);
    }
}
