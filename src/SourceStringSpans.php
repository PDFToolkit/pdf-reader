<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\StringType;

/** Document-scoped provenance; decoded object-stream strings never acquire source-file spans. */
final class SourceStringSpans
{
    /** @var \WeakMap<StringType, SourceStringSpan> */
    private \WeakMap $spans;

    public function __construct(private readonly ReadBudget $budget)
    {
        $this->spans = new \WeakMap();
    }

    public function record(StringType $value, int $start, int $end): void
    {
        $this->budget->retained(64);
        $this->spans[$value] = new SourceStringSpan($start, $end);
    }

    public function of(StringType $value): ?SourceStringSpan
    {
        return $this->spans[$value] ?? null;
    }
}
