<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** Exact physical extent of one ordinary indirect object, through its endobj token. */
final readonly class SourceObjectSpan
{
    public function __construct(public ObjectIdentifier $identifier, public int $start, public int $end)
    {
        if ($start < 0 || $end <= $start) {
            throw new \InvalidArgumentException('An indirect-object span has an increasing nonnegative extent.');
        }
    }
}
