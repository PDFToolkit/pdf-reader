<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Immutable physical revision view; its raw graph grants no authentication or modification authority. */
final readonly class ReadRevision
{
    public function __construct(
        public int $ordinal,
        public PhysicalCrossReference $crossReference,
        public RevisionBoundary $boundary,
        private ReadDocument $raw,
        private FileBytes $original,
        private ReadBudget $budget,
    ) {
        if ($ordinal < 0 || strlen($raw->sourceRevision()->source->bytes) !== $boundary->endOffset
            || $boundary->maximumEndOffset > strlen($original->bytes)
            || !str_starts_with($original->bytes, $raw->sourceRevision()->source->bytes)) {
            throw new \InvalidArgumentException('A physical revision retains the exact source prefix and its ordered bounds.');
        }
    }

    public function rawDocument(): ReadDocument
    {
        return $this->raw;
    }

    public function acceptsByteLength(int $length): bool
    {
        return $this->boundary->acceptsByteLength($length);
    }

    /** Choose exact bytes for a separate authentication operation; no whitespace is normalized. */
    public function source(?int $byteLength = null): FileBytes
    {
        $byteLength ??= $this->boundary->endOffset;
        if (!$this->acceptsByteLength($byteLength)) {
            throw new ParseException('The selected byte length is outside this physical revision EOF interval.');
        }
        if ($byteLength === $this->boundary->endOffset) {
            return $this->raw->sourceRevision()->source;
        }
        $this->budget->retained($byteLength);
        return new FileBytes(substr($this->original->bytes, 0, $byteLength));
    }
}
