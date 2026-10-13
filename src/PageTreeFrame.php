<?php

declare(strict_types=1);

namespace PDF\Reading;

/** One traversal frame; a child is visited before the next sibling is scheduled. */
final class PageTreeFrame
{
    private int $next = 0;
    private int $descendants = 0;
    /** @var list<ObjectReference> */
    private readonly array $kids;

    public function __construct(
        public readonly ObjectReference $reference,
        public readonly PageInheritance $inheritance,
        public readonly int $declaredCount,
        ObjectReference ...$kids,
    ) {
        if ($declaredCount < 0) {
            throw new \InvalidArgumentException('A page-tree descendant count must be nonnegative.');
        }
        $this->kids = array_values($kids);
    }

    public function next(): ?ObjectReference
    {
        return $this->kids[$this->next++] ?? null;
    }

    public function addPages(int $count): void
    {
        if ($count < 0 || $count > $this->declaredCount - $this->descendants) {
            throw new ParseException('A page-tree Count is smaller than its actual descendants.');
        }
        $this->descendants += $count;
    }

    public function completedCount(): int
    {
        if ($this->descendants !== $this->declaredCount) {
            throw new ParseException('A page-tree Count differs from its actual descendants.');
        }
        return $this->descendants;
    }
}
