<?php

declare(strict_types=1);

namespace PDF\Reading;

/** An EOF token plus the interval of trailing PDF whitespace, not a chosen signed byte range. */
final readonly class RevisionBoundary
{
    public function __construct(public int $footerOffset, public int $eofOffset, public int $eofMarkerEnd, public int $endOffset, public int $maximumEndOffset)
    {
        if ($footerOffset < 0 || $eofOffset <= $footerOffset || $eofMarkerEnd !== $eofOffset + 5 || $endOffset < $eofMarkerEnd || $maximumEndOffset < $endOffset) {
            throw new \InvalidArgumentException('Revision EOF bounds are ordered and preserve the five-byte marker.');
        }
    }

    public function acceptsByteLength(int $length): bool
    {
        return $length >= $this->eofMarkerEnd && $length <= $this->maximumEndOffset;
    }
}
