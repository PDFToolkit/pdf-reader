<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Explicit resource ceilings shared by every stage of one PDF read. */
final readonly class ReaderLimits
{
    public function __construct(
        public int $maxFileBytes = 67108864,
        public int $maxDepth = 64,
        public int $maxTokenBytes = 1048576,
        public int $maxContainerEntries = 100000,
        public int $maxObjects = 100000,
        public int $maxCrossReferenceEntries = 200000,
        public int $maxRevisions = 64,
        public int $maxDecodedStreamBytes = 67108864,
        public int $maxTotalDecodedBytes = 134217728,
        public int $maxFilters = 16,
        public int $maxParsedValues = 500000,
        public int $maxRetainedBytes = 134217728,
    ) {
        foreach (get_object_vars($this) as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('PDF reader limits must be positive integers.');
            }
        }
    }
}
