<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Per traversal ceilings; syntax depth alone does not bound an indirect page tree. */
final readonly class PageTreeLimits
{
    public function __construct(public int $maxDepth = 64, public int $maxNodes = 100000, public int $maxPages = 100000)
    {
        if ($maxDepth < 1 || $maxNodes < 1 || $maxPages < 1) {
            throw new \InvalidArgumentException('Page-tree limits must be positive integers.');
        }
    }
}
