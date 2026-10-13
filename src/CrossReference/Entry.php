<?php

declare(strict_types=1);

namespace PDF\Reading\CrossReference;

/** An effective xref record; its two fields retain their kind-specific meaning. */
final readonly class Entry
{
    public function __construct(public int $number, public EntryKind $kind, public int $firstField, public int $secondField)
    {
        if ($number < 0 || $firstField < 0 || $secondField < 0) {
            throw new \InvalidArgumentException('Cross-reference fields must be non-negative.');
        }
    }

    public function generation(): int
    {
        return $this->kind === EntryKind::Compressed ? 0 : $this->secondField;
    }
}
