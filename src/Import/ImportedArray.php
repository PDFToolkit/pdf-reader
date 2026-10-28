<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;

/** @internal Readonly items are initialized exactly once, before publication of the graph. */
final class ImportedArray extends ArrayType
{
    private bool $sealed = false;

    public function __construct()
    {
    }

    public function seal(BasicType ...$items): void
    {
        if ($this->sealed) {
            throw new \LogicException('An imported array is already sealed.');
        }
        $this->sealed = true;
        parent::__construct(...$items);
    }
}
