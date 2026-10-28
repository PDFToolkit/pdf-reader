<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\EncodedStream;
use PDF\Reading\ParseException;

/** One explicit stream decoding operation, including standalone JBIG2 globals. @internal */
final class DecodeContext
{
    /** @var \SplObjectStorage<EncodedStream, null> */
    private \SplObjectStorage $active;

    public function __construct(private readonly int $maximumDepth)
    {
        $this->active = new \SplObjectStorage();
    }

    public function enter(EncodedStream $stream): void
    {
        if ($this->active->offsetExists($stream) || count($this->active) >= $this->maximumDepth) {
            throw new ParseException('Image/global stream decoding contains a cycle or exceeds its depth limit.');
        }
        $this->active->offsetSet($stream, null);
    }

    public function leave(EncodedStream $stream): void
    {
        $this->active->offsetUnset($stream);
    }
}
