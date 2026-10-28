<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Explicit image budgets, additionally capped by the enclosing Reader budgets. */
final readonly class ImageLimits
{
    public function __construct(
        public int $maxPixels = 16777216,
        public int $maxDimension = 65536,
        public int $maxComponents = 16,
        public int $maxWork = 100000000,
        public int $maxMemoryBytes = 268435456,
        public int $maxSegments = 100000,
        public int $maxMetadataBytes = 4194304,
        public int $maxTiles = 4096,
        public int $nativeMemoryBytes = 805306368,
        public int $nativeCpuSeconds = 10,
        public int $nativeWallMilliseconds = 15000,
        public int $maxDiagnosticsBytes = 65536,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Image codec limits must be positive.');
            }
        }
    }
}
