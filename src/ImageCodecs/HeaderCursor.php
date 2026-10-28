<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** Bounded metadata cursor; this never decodes compressed image content. @internal */
final class HeaderCursor
{
    public int $position = 0;
    public readonly int $length;

    public function __construct(private readonly string $bytes, private readonly ?InspectionBudget $budget = null)
    {
        $this->length = strlen($bytes);
    }

    public function read(int $length): string
    {
        if ($length < 0 || $length > $this->length - $this->position) {
            throw new ParseException('Truncated image codec metadata.');
        }
        $this->budget?->work($length);
        $result = substr($this->bytes, $this->position, $length);
        $this->position += $length;
        return $result;
    }

    /** @phpstan-impure */
    public function byte(): int
    {
        return ord($this->read(1));
    }

    /** @phpstan-impure */
    public function word(): int
    {
        return $this->byte() * 256 + $this->byte();
    }

    /** @phpstan-impure */
    public function uint32(): int
    {
        return $this->word() * 65536 + $this->word();
    }
}
