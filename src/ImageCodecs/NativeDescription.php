<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** Only profiles whose original planes survive the native filter are described here. @internal */
final readonly class NativeDescription
{
    public int $rowBytes;
    public int $byteLength;

    public function __construct(public int $width, public int $height, public int $components, public int $precision, public ColorInformation $color, CodecRequest $request, public ?int $colorTransform = null, ?InspectionBudget $inspection = null, public int $minimumGhostscriptRevision = 0)
    {
        $limits = $request->limits;
        if ($width < 1 || $height < 1 || $components < 1) {
            throw new ParseException('Image header has invalid dimensions or component count.');
        }
        if ($width > $limits->maxDimension || $height > $limits->maxDimension || $width > intdiv($limits->maxPixels, $height)
            || $components > $limits->maxComponents || $width > intdiv(intdiv($limits->maxWork, $components * $precision), $height)) {
            throw new CodecLimit('Image geometry exceeds its dimension, pixel, component or work limit.');
        }
        $inspection?->work($width * $height * $components * $precision);
        $availableMemory = $inspection?->remainingMemory() ?? $limits->maxMemoryBytes;
        $this->rowBytes = intdiv($width * $components * $precision + 7, 8);
        $planeRowBytes = intdiv($width * $precision + 7, 8);
        if ($this->rowBytes > intdiv($request->maximumOutputBytes, $height)
            || $planeRowBytes > intdiv($request->maximumOutputBytes, $height * $components)
            || $planeRowBytes > intdiv($availableMemory, $height * $components * 3)) {
            throw new CodecLimit('Image samples exceed their output or intermediate allocation limit.');
        }
        $this->byteLength = $this->rowBytes * $height;
    }

    public function planes(string $bytes): DecodedImage
    {
        if (strlen($bytes) !== $this->byteLength) {
            throw new ParseException('Native image output size disagrees with encoded geometry.');
        }
        $planes = [];
        $rowBytes = intdiv($this->width * $this->precision + 7, 8);
        for ($component = 0; $component < $this->components; ++$component) {
            $plane = str_repeat("\0", $rowBytes * $this->height);
            for ($y = 0; $y < $this->height; ++$y) {
                for ($x = 0; $x < $this->width; ++$x) {
                    $source = $y * $this->rowBytes * 8 + ($x * $this->components + $component) * $this->precision;
                    $target = $y * $rowBytes * 8 + $x * $this->precision;
                    for ($bit = 0; $bit < $this->precision; ++$bit, ++$source, ++$target) {
                        if ((ord($bytes[intdiv($source, 8)]) & (1 << (7 - ($source & 7)))) !== 0) {
                            $offset = intdiv($target, 8);
                            $plane[$offset] = chr(ord($plane[$offset]) | (1 << (7 - ($target & 7))));
                        }
                    }
                }
            }
            $planes[] = new ComponentPlane($this->width, $this->height, $this->precision, false, $plane);
        }
        if ($planes === []) {
            throw new ParseException('Native image has no component planes.');
        }
        return new DecodedImage($this->width, $this->height, $planes, $this->color, backend: 'ghostscript');
    }
}
