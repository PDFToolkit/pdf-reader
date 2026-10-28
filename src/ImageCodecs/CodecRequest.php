<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Encoded component data only; no path or executable data is supplied by a PDF. */
final readonly class CodecRequest
{
    public function __construct(
        public string $filter,
        public string $bytes,
        public int $maximumInputBytes,
        public int $maximumOutputBytes,
        public int $maximumDepth,
        public ImageLimits $limits = new ImageLimits(),
        public ?int $colorTransform = null,
        public ?string $globals = null,
    ) {
        if (!in_array($filter, ['DCTDecode', 'JPXDecode', 'JBIG2Decode'], true) || $maximumInputBytes < 1 || $maximumOutputBytes < 1 || $maximumDepth < 1) {
            throw new \InvalidArgumentException('Invalid image codec request.');
        }
        if (($colorTransform !== null && ($filter !== 'DCTDecode' || !in_array($colorTransform, [0, 1], true))) || ($globals !== null && $filter !== 'JBIG2Decode')) {
            throw new \InvalidArgumentException('Image codec parameters do not match their filter.');
        }
        if (strlen($bytes) > $maximumInputBytes - strlen($globals ?? '')) {
            throw new CodecLimit('Image codec input exceeds its configured byte limit.');
        }
        if (strlen($bytes) + strlen($globals ?? '') > intdiv($limits->maxMemoryBytes, 3)) {
            throw new CodecLimit('Image codec input exceeds its metadata and native pipe allocation limit.');
        }
    }
}
