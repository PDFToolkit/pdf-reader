<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;

/** Checks both budgets before retaining an output slice. */
final class DecodedBytes
{
    private string $bytes = '';

    public function __construct(private readonly int $maximum, private readonly ReadBudget $budget)
    {
        if ($maximum < 1) {
            throw new \InvalidArgumentException('The decoded stream limit must be positive.');
        }
    }

    public function append(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length > $this->maximum - strlen($this->bytes)) {
            throw new ParseException('Decoded stream exceeds the configured byte limit.');
        }
        $this->budget->decoded($length);
        $this->bytes .= $bytes;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }
}
