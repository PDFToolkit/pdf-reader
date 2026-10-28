<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

/** Local ceilings for one atomic graph copy, in addition to the source reader's limits. */
final readonly class ImportLimits
{
    public function __construct(
        public int $maxObjects = 100000,
        public int $maxValues = 500000,
        public int $maxDepth = 64,
        public int $maxBytes = 134217728,
        public int $maxStreamBytes = 67108864,
        public int $maxFilters = 16,
        public int $maxCycles = 100000,
        public int $maxExternalRequests = 64,
    ) {
        foreach (get_object_vars($this) as $name => $limit) {
            if ($limit < ($name === 'maxCycles' ? 0 : 1)) {
                throw new \InvalidArgumentException('Import limits must be positive, except the cycle allowance which may be zero.');
            }
        }
    }
}
