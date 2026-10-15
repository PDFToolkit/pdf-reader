<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;

/** Reconstructs TIFF rows or tagged PNG rows after decompression, ISO 32000-1, 7.4.4.4. */
final readonly class PredictorDecoder implements Decoder
{
    public function __construct(
        private PredictorParameters $parameters = new PredictorParameters(),
        private ReaderLimits $limits = new ReaderLimits(),
    ) {
    }

    public function decode(string $bytes, ReadBudget $budget): string
    {
        if ($this->parameters->predictor === 1) {
            return $bytes;
        }
        $rowBytes = $this->parameters->rowBytes();
        if ($rowBytes > $this->limits->maxDecodedStreamBytes) {
            throw new ParseException('Predictor row exceeds the decoded stream byte limit.');
        }
        $png = $this->parameters->predictor >= 10;
        $stride = $rowBytes + ($png ? 1 : 0);
        $length = strlen($bytes);
        if ($length % $stride !== 0) {
            throw new ParseException('Predictor data ends inside a row.');
        }
        if ($length === 0) {
            return '';
        }
        $output = new DecodedBytes($this->limits->maxDecodedStreamBytes, $budget);
        $previous = str_repeat("\0", $rowBytes);
        $pixelBytes = $this->parameters->bytesPerPixel();
        for ($offset = 0; $offset < $length;) {
            // PNG tags, rather than the dictionary's Predictor value, select each row's algorithm.
            $filter = $png ? ord($bytes[$offset++]) : 1;
            if ($filter > 4) {
                throw new ParseException('PNG stream predictor has an invalid row tag.');
            }
            $row = substr($bytes, $offset, $rowBytes);
            $offset += $rowBytes;
            if (!$png) {
                $output->append($this->tiffRow($row));
                continue;
            }
            for ($index = 0; $index < $rowBytes; ++$index) {
                $left = $index < $pixelBytes ? 0 : ord($row[$index - $pixelBytes]);
                $above = ord($previous[$index]);
                $upperLeft = $index < $pixelBytes ? 0 : ord($previous[$index - $pixelBytes]);
                $prediction = match ($filter) {
                    0 => 0,
                    1 => $left,
                    2 => $above,
                    3 => intdiv($left + $above, 2),
                    4 => $this->paeth($left, $above, $upperLeft),
                };
                $row[$index] = chr((ord($row[$index]) + $prediction) & 255);
            }
            $output->append($row);
            $previous = $row;
        }
        return $output->bytes();
    }

    private function tiffRow(string $row): string
    {
        $bits = $this->parameters->bitsPerComponent;
        $mask = (1 << $bits) - 1;
        $samples = $this->parameters->colors * $this->parameters->columns;
        for ($sample = $this->parameters->colors; $sample < $samples; ++$sample) {
            $value = ($this->sample($row, $sample) + $this->sample($row, $sample - $this->parameters->colors)) & $mask;
            $byte = intdiv($sample * $bits, 8);
            if ($bits === 16) {
                $row[$byte] = chr($value >> 8);
                $row[$byte + 1] = chr($value & 255);
            } else {
                $shift = 8 - $bits - ($sample * $bits % 8);
                $row[$byte] = chr((ord($row[$byte]) & ~(($mask) << $shift)) | ($value << $shift));
            }
        }
        return $row;
    }

    private function sample(string $row, int $sample): int
    {
        $bits = $this->parameters->bitsPerComponent;
        $byte = intdiv($sample * $bits, 8);
        if ($bits === 16) {
            return (ord($row[$byte]) << 8) | ord($row[$byte + 1]);
        }
        return (ord($row[$byte]) >> (8 - $bits - ($sample * $bits % 8))) & ((1 << $bits) - 1);
    }

    private function paeth(int $left, int $above, int $upperLeft): int
    {
        $estimate = $left + $above - $upperLeft;
        $leftDistance = abs($estimate - $left);
        $aboveDistance = abs($estimate - $above);
        $upperLeftDistance = abs($estimate - $upperLeft);
        if ($leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }
        return $aboveDistance <= $upperLeftDistance ? $above : $upperLeft;
    }
}
