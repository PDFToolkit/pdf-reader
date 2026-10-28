<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StreamObject;

/** @internal The native stream is allocated before its dictionary references are remapped. */
final class ImportedStream extends StreamObject
{
    private bool $sealed = false;

    public function __construct()
    {
    }

    public function seal(string $bytes, DictionaryType $dictionary, Filter ...$filters): void
    {
        if ($this->sealed) {
            throw new \LogicException('An imported stream is already sealed.');
        }
        $this->sealed = true;
        parent::__construct($bytes, $dictionary, ...$filters);
    }
}
