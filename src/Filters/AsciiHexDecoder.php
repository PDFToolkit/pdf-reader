<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** ISO 32000-1, 7.4.2; the final odd nibble is padded with zero. */
final readonly class AsciiHexDecoder implements Decoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        $pending = null;
        for ($offset = 0, $length = strlen($bytes); $offset < $length; ++$offset) {
            $character = $bytes[$offset];
            if (str_contains("\0\t\n\f\r ", $character)) {
                continue;
            }
            if ($character === '>') {
                if ($pending !== null) {
                    $output->append(chr($pending * 16));
                }
                $this->trailingWhitespace($bytes, $offset + 1);
                return $output->bytes();
            }
            if (!ctype_xdigit($character)) {
                throw new ParseException('ASCIIHexDecode contains a non-hexadecimal byte.');
            }
            $nibble = (int) hexdec($character);
            if ($pending === null) {
                $pending = $nibble;
            } else {
                $output->append(chr($pending * 16 + $nibble));
                $pending = null;
            }
        }
        throw new ParseException('ASCIIHexDecode is missing its end-of-data marker.');
    }

    private function trailingWhitespace(string $bytes, int $offset): void
    {
        if (strspn($bytes, "\0\t\n\f\r ", $offset) !== strlen($bytes) - $offset) {
            throw new ParseException('ASCIIHexDecode contains data after its end marker.');
        }
    }
}
