<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Exact lexical extent in the original file, including the string delimiters. */
final readonly class SourceStringSpan
{
    public function __construct(public int $start, public int $end)
    {
        if ($start < 0 || $end <= $start) {
            throw new \InvalidArgumentException('A source string span requires an increasing, nonnegative extent.');
        }
    }
}
