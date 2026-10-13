<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Supplied PDF bytes, independent of paths, URLs and filesystem access. */
final readonly class FileBytes
{
    public function __construct(public string $bytes)
    {
    }
}
