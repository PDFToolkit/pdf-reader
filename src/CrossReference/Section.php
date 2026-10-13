<?php

declare(strict_types=1);

namespace PDF\Reading\CrossReference;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\DictionaryType;

/** One table or stream and its trailer; revision precedence is handled separately. */
final readonly class Section
{
    /** @var list<Entry> */
    public array $entries;

    public function __construct(public ByteOffset $offset, public DictionaryType $trailer, public ?ByteOffset $previous, Entry ...$entries)
    {
        $this->entries = array_values($entries);
    }
}
