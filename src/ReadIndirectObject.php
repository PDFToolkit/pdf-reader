<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\IndirectObject;

/** Parsed physical bytes and their ordinary indirect-object identity; not signature evidence. */
final readonly class ReadIndirectObject
{
    public function __construct(public IndirectObject $object, public SourceObjectSpan $span)
    {
        if ((string) $object->identifier !== (string) $span->identifier) {
            throw new \InvalidArgumentException('The source span identifies its actual parsed object.');
        }
    }
}
