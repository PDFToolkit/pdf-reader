<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Application-supplied acquisition; no filesystem, URL or embedded-file lookup is implicit. */
interface ExternalStreamSource
{
    /** Bound acquisition before allocating bytes; the reader checks the returned size again. */
    public function read(ExternalStreamRequest $request): FileBytes;
}
