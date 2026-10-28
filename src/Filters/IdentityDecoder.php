<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** The standard Identity crypt filter changes no bytes and still accounts for its output. */
final readonly class IdentityDecoder implements Decoder
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        $output->append($bytes);
        return $output->bytes();
    }
}
