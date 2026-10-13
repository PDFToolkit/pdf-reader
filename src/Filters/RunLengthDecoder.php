<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** ISO 32000-1, 7.4.5; packets are validated before reading their payload. */
final readonly class RunLengthDecoder implements Decoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $packet = ord($bytes[$offset++]);
            if ($packet === 128) {
                if ($offset !== $length) {
                    throw new ParseException('RunLengthDecode contains data after its end marker.');
                }
                return $output->bytes();
            }
            if ($packet < 128) {
                $count = $packet + 1;
                if ($count > $length - $offset) {
                    throw new ParseException('RunLengthDecode literal packet is truncated.');
                }
                $output->append(substr($bytes, $offset, $count));
                $offset += $count;
            } else {
                if ($offset >= $length) {
                    throw new ParseException('RunLengthDecode repeated packet is truncated.');
                }
                $output->append(str_repeat($bytes[$offset++], 257 - $packet));
            }
        }
        throw new ParseException('RunLengthDecode is missing its end-of-data marker.');
    }
}
