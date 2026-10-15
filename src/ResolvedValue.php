<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** @internal A document resolver's terminal value and physical object identity. */
final readonly class ResolvedValue
{
    public function __construct(public BasicType $value, public ?ObjectIdentifier $terminalId)
    {
    }
}
