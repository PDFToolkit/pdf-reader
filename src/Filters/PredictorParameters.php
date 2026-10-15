<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;

/** ISO 32000-1, Table 8: byte-padded rows with 1, 2, 4, 8 or 16-bit components. */
final readonly class PredictorParameters
{
    public function __construct(
        public int $predictor = 1,
        public int $colors = 1,
        public int $bitsPerComponent = 8,
        public int $columns = 1,
    ) {
        if ($predictor !== 1 && $predictor !== 2 && ($predictor < 10 || $predictor > 15)) {
            throw new ParseException('FlateDecode Predictor must be 1, 2 or 10 through 15.');
        }
        if ($colors < 1 || $columns < 1) {
            throw new ParseException('Predictor Colors and Columns must be positive integers.');
        }
        if (!in_array($bitsPerComponent, [1, 2, 4, 8, 16], true)) {
            throw new ParseException('Predictor BitsPerComponent is invalid.');
        }
    }

    public function rowBytes(): int
    {
        if ($this->columns > intdiv(PHP_INT_MAX, $this->colors)
            || $this->columns * $this->colors > intdiv(PHP_INT_MAX, $this->bitsPerComponent)) {
            throw new ParseException('Predictor row length exceeds the integer range.');
        }
        $bits = $this->columns * $this->colors * $this->bitsPerComponent;
        return intdiv($bits, 8) + ($bits % 8 === 0 ? 0 : 1);
    }

    public function bytesPerPixel(): int
    {
        // rowBytes checks the larger row product before computing this product.
        $this->rowBytes();
        $bits = $this->colors * $this->bitsPerComponent;
        return intdiv($bits, 8) + ($bits % 8 === 0 ? 0 : 1);
    }
}
