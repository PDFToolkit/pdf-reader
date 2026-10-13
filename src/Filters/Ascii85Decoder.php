<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** ISO 32000-1, 7.4.3; PDF data has no PostScript <~ prefix. */
final readonly class Ascii85Decoder implements Decoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
        if (PHP_INT_SIZE < 8) {
            throw new \LogicException('ASCII85 decoding requires 64-bit PHP.');
        }
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        $value = 0;
        $digits = 0;
        for ($offset = 0, $length = strlen($bytes); $offset < $length; ++$offset) {
            $character = $bytes[$offset];
            if (str_contains("\0\t\n\f\r ", $character)) {
                continue;
            }
            if ($character === '~') {
                if ($offset + 1 >= $length || $bytes[$offset + 1] !== '>') {
                    throw new ParseException('ASCII85Decode has an invalid end-of-data marker.');
                }
                if ($digits === 1) {
                    throw new ParseException('ASCII85Decode has a one-character final group.');
                }
                if ($digits > 1) {
                    $count = $digits - 1;
                    while ($digits++ < 5) {
                        $value = $value * 85 + 84;
                    }
                    $output->append(substr($this->word($value), 0, $count));
                }
                if (strspn($bytes, "\0\t\n\f\r ", $offset + 2) !== $length - $offset - 2) {
                    throw new ParseException('ASCII85Decode contains data after its end marker.');
                }
                return $output->bytes();
            }
            if ($character === 'z') {
                if ($digits !== 0) {
                    throw new ParseException('ASCII85Decode has a zero abbreviation inside a group.');
                }
                $output->append("\0\0\0\0");
                continue;
            }
            $digit = ord($character) - 33;
            if ($digit < 0 || $digit > 84) {
                throw new ParseException('ASCII85Decode contains an invalid byte.');
            }
            $value = $value * 85 + $digit;
            if (++$digits === 5) {
                $output->append($this->word($value));
                $digits = $value = 0;
            }
        }
        throw new ParseException('ASCII85Decode is missing its end-of-data marker.');
    }

    private function word(int $value): string
    {
        if ($value > 0xffffffff) {
            throw new ParseException('ASCII85Decode word exceeds the unsigned 32-bit range.');
        }
        return pack('N', $value);
    }
}
