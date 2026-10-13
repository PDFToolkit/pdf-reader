<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Per page inspection ceiling; decoded bytes retain the document's ReaderLimits. */
final readonly class PageContentLimits
{
    public function __construct(public int $maxStreams = 1024)
    {
        if ($maxStreams < 1) {
            throw new \InvalidArgumentException('The page content-stream limit must be positive.');
        }
    }
}
