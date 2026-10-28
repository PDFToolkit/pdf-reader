<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Exact row-padded MSB-first samples, independent of the optional codec package. */
final readonly class ComponentPlane
{
    public int $rowBytes;

    public function __construct(
        public int $width,
        public int $height,
        public int $precision,
        public bool $signed,
        public string $bytes,
        public int $separationX = 1,
        public int $separationY = 1,
        public int $originX = 0,
        public int $originY = 0,
        public string $alpha = 'none',
    ) {
        if ($width < 1 || $height < 1 || $precision < 1 || $precision > 38 || $separationX < 1 || $separationY < 1
            || $originX < 0 || $originY < 0 || !in_array($alpha, ['none', 'unassociated', 'associated'], true)
            || $width > intdiv(PHP_INT_MAX - 7, $precision)) {
            throw new \InvalidArgumentException('Invalid image component geometry or precision.');
        }
        $this->rowBytes = intdiv($width * $precision + 7, 8);
        if ($height > intdiv(PHP_INT_MAX, $this->rowBytes) || strlen($bytes) !== $this->rowBytes * $height) {
            throw new \InvalidArgumentException('Image component bytes disagree with their geometry.');
        }
    }

    public function sample(int $x, int $y): int
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            throw new \OutOfBoundsException('Sample is outside the image component.');
        }
        $position = $y * $this->rowBytes * 8 + $x * $this->precision;
        $value = 0;
        for ($i = 0; $i < $this->precision; ++$i, ++$position) {
            $value = ($value << 1) | ((ord($this->bytes[intdiv($position, 8)]) >> (7 - ($position & 7))) & 1);
        }
        return $this->signed && ($value & (1 << ($this->precision - 1))) !== 0 ? $value - (1 << $this->precision) : $value;
    }
}
