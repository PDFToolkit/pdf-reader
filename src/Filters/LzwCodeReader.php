<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;

/** Document-local, MSB-first PDF LZW code input (ISO 32000-1, 7.4.4.2). */
final class LzwCodeReader
{
    private int $offset = 0;
    private int $buffer = 0;
    private int $available = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    public function read(int $width): int
    {
        if ($width < 9 || $width > 12) {
            throw new \InvalidArgumentException('PDF LZW code widths are nine through twelve bits.');
        }
        while ($this->available < $width) {
            if ($this->offset >= strlen($this->bytes)) {
                throw new ParseException('LZW data ends before an EOD code.');
            }
            $this->buffer = ($this->buffer << 8) | ord($this->bytes[$this->offset++]);
            $this->available += 8;
        }
        $this->available -= $width;
        $code = ($this->buffer >> $this->available) & ((1 << $width) - 1);
        $this->buffer &= (1 << $this->available) - 1;
        return $code;
    }
}
