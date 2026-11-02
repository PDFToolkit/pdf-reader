<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

/** Independent cumulative ceilings for one inspection, in addition to reader limits. */
final readonly class InspectionLimits
{
    public function __construct(
        public int $maxDepth = 48,
        public int $maxNodes = 5000,
        public int $maxPages = 5000,
        public int $maxObjects = 2000,
        public int $maxValues = 50000,
        public int $maxBytes = 2097152,
        public int $maxStringBytes = 2048,
        public int $maxAssociations = 2000,
        public int $maxDiagnostics = 100,
    ) {
        foreach (get_object_vars($this) as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Inspection limits must be positive integers.');
            }
        }
    }
}
