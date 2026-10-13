<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Ordered original streams, without concatenation or implicit decoding.
 * @implements \IteratorAggregate<int, EncodedStream>
 */
final readonly class PageContents implements \Countable, \IteratorAggregate
{
    /** @var list<EncodedStream> */
    public array $streams;

    public function __construct(public ReadPage $page, EncodedStream ...$streams)
    {
        $this->streams = array_values($streams);
    }

    public function count(): int
    {
        return count($this->streams);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->streams;
    }
}
