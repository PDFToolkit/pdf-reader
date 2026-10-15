<?php

declare(strict_types=1);

namespace PDF\Reading;

/**
 * Physical source views ordered oldest to newest, without any trust or signature verdict.
 * @implements \IteratorAggregate<int, ReadRevision>
 */
final readonly class ReadRevisionHistory implements \Countable, \IteratorAggregate
{
    /** @var non-empty-list<ReadRevision> */
    private array $revisions;

    public function __construct(public FileBytes $source, ReadRevision $first, ReadRevision ...$following)
    {
        $this->revisions = [$first, ...array_values($following)];
        $previous = null;
        foreach ($this->revisions as $ordinal => $revision) {
            if ($revision->ordinal !== $ordinal || ($previous !== null && $revision->crossReference->section->previous?->value !== $previous->crossReference->section->offset->value)) {
                throw new \InvalidArgumentException('Physical revision views preserve the actual ordered Prev chain.');
            }
            $previous = $revision;
        }
    }

    public function latest(): ReadRevision
    {
        return $this->revisions[array_key_last($this->revisions)];
    }

    public function count(): int
    {
        return count($this->revisions);
    }

    /** @return \Traversable<int, ReadRevision> */
    public function getIterator(): \Traversable
    {
        yield from $this->revisions;
    }
}
