<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

/** Additional codec work bounds; decoded and retained bytes also use the shared document budget. */
final readonly class CcittFaxLimits
{
    public function __construct(
        public int $maxBitReads = 134_217_728,
        public int $maxRows = 1_000_000,
        public int $maxRowTransitions = 1_000_000,
        public int $maxRecoveryBits = 1_048_576,
    ) {
        if (min($maxBitReads, $maxRows, $maxRowTransitions, $maxRecoveryBits) < 1) {
            throw new \InvalidArgumentException('CCITT work limits must be positive integers.');
        }
    }
}
