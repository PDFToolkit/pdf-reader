<?php

declare(strict_types=1);

namespace PDF\Reading\CrossReference;

/** Sparse effective entries. Construction receives sections from newest to oldest. */
final readonly class Index
{
    /** @var array<int, Entry> */
    private array $entries;

    public function __construct(Section ...$sections)
    {
        $entries = [];
        foreach ($sections as $section) {
            foreach ($section->entries as $entry) {
                $entries[$entry->number] ??= $entry;
            }
        }
        $this->entries = $entries;
    }

    public function entry(int $number): ?Entry
    {
        return $this->entries[$number] ?? null;
    }
}
