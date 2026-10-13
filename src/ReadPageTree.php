<?php

declare(strict_types=1);

namespace PDF\Reading;

/** An ordered page snapshot; the reader verifies each subtree before returning it.
 * @implements \IteratorAggregate<int, ReadPage>
 */
final readonly class ReadPageTree implements \Countable, \IteratorAggregate
{
    /** @var list<ReadPage> */
    public array $pages;

    public function __construct(public ReadDocument $document, public ObjectReference $root, ReadPage ...$pages)
    {
        $seen = [];
        foreach ($pages as $page) {
            $key = (string) $page->reference->identifier;
            if ($page->document !== $document || isset($seen[$key])) {
                throw new \InvalidArgumentException('A page tree requires unique pages from its source document.');
            }
            $seen[$key] = true;
        }
        $this->pages = array_values($pages);
    }

    public function count(): int
    {
        return count($this->pages);
    }

    public function page(int $index): ReadPage
    {
        return $this->pages[$index] ?? throw new \OutOfRangeException('The zero-based page index is outside this tree.');
    }

    public function getIterator(): \Traversable
    {
        yield from $this->pages;
    }
}
