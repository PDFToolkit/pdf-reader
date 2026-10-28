<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

/** Exact codec boundary and samples, plus work counters for a containing parser's budget. */
final readonly class CcittFaxPrefix
{
    public function __construct(public string $samples, public int $encodedBytes, public int $rows, public int $bitReads, public int $transitions)
    {
    }
}
