<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\UnsupportedPdfFeature;

/** The byte-aligned subset of ISO 32000-1, Table 8. */
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
        if ($predictor !== 1 && $bitsPerComponent !== 8) {
            throw new UnsupportedPdfFeature('Stream predictors currently support only 8-bit components.');
        }
    }

    public function rowBytes(): int
    {
        if ($this->columns > intdiv(PHP_INT_MAX - 1, $this->colors)) {
            throw new ParseException('Predictor row length exceeds the integer range.');
        }
        return $this->columns * $this->colors;
    }
}
