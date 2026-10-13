<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** ISO 32000-1, 7.4.4; small compressed slices also bound temporary zlib output. */
final readonly class FlateDecoder implements Decoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new ParseException('Could not initialize FlateDecode.');
        }
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length; $offset += 64) {
            $part = @inflate_add($context, substr($bytes, $offset, 64), ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw new ParseException('FlateDecode contains invalid compressed data.');
            }
            $output->append($part);
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                if (inflate_get_read_len($context) !== $length) {
                    throw new ParseException('FlateDecode contains trailing compressed data.');
                }
                return $output->bytes();
            }
        }
        throw new ParseException('FlateDecode compressed data is truncated.');
    }
}
