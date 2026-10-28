<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Immutable component planes and their intrinsic geometry. */
final readonly class DecodedImage
{
    /**
     * @param non-empty-list<ComponentPlane> $components
     * @param list<ChannelDefinition> $channelDefinitions
     * @param list<int> $chromaKey JPX opacity metadata; ignored by PDF image composition.
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $components,
        public ColorInformation $color = new ColorInformation(),
        public int $originX = 0,
        public int $originY = 0,
        public string $backend = 'application',
        public array $channelDefinitions = [],
        public array $chromaKey = [],
    ) {
        if ($width < 1 || $height < 1 || $components === [] || !array_is_list($components)) {
            throw new \InvalidArgumentException('A decoded image needs dimensions and component planes.');
        }
    }

    public function byteLength(): int
    {
        $size = 0;
        foreach ($this->components as $component) {
            $size += strlen($component->bytes);
        }
        return $size;
    }

    /** Conservative retained-byte accounting includes copied colour metadata. */
    public function retainedBytes(): int
    {
        $size = $this->byteLength() + strlen($this->color->iccProfile ?? '') + 128
            + 128 * count($this->components) + 64 * count($this->channelDefinitions) + 16 * count($this->chromaKey);
        foreach ($this->color->specifications as $specification) {
            $size += 64 + strlen($specification->methodData) + strlen($specification->iccProfile ?? '') + 16 * count($specification->parameters);
        }
        return $size;
    }

    /** Interleave only a representation that does not discard plane metadata. */
    public function streamBytes(int $maximum, ImageLimits $limits): string
    {
        $precision = $this->components[0]->precision;
        foreach ($this->components as $plane) {
            if ($plane->width !== $this->width || $plane->height !== $this->height || $plane->precision !== $precision
                || $plane->signed || $plane->separationX !== 1 || $plane->separationY !== 1 || $plane->originX !== 0 || $plane->originY !== 0
                || $plane->alpha !== 'none' || $this->originX !== 0 || $this->originY !== 0) {
                throw new CodecUnavailable('This image has distinct precision, geometry, signedness or alpha metadata; use ReadDocument::imageData() to retain its exact planes.');
            }
        }
        $count = count($this->components);
        $rowBytes = intdiv($this->width * $count * $precision + 7, 8);
        if ($rowBytes > intdiv($maximum, $this->height)
            || $rowBytes > intdiv($limits->maxMemoryBytes - min($limits->maxMemoryBytes, $this->byteLength()), $this->height)
            || $this->width * $this->height * $count > intdiv($limits->maxWork, $precision)) {
            throw new CodecLimit('Interleaved image samples exceed their byte or work limit.');
        }
        if ($count === 1) {
            return $this->components[0]->bytes;
        }
        $output = str_repeat("\0", $rowBytes * $this->height);
        for ($y = 0; $y < $this->height; ++$y) {
            $destination = $y * $rowBytes * 8;
            for ($x = 0; $x < $this->width; ++$x) {
                foreach ($this->components as $component) {
                    $source = $y * $component->rowBytes * 8 + $x * $precision;
                    for ($bit = 0; $bit < $precision; ++$bit, ++$source, ++$destination) {
                        if ((ord($component->bytes[intdiv($source, 8)]) & (1 << (7 - ($source & 7)))) !== 0) {
                            $index = intdiv($destination, 8);
                            $output[$index] = chr(ord($output[$index]) | (1 << (7 - ($destination & 7))));
                        }
                    }
                }
            }
        }
        return $output;
    }
}
