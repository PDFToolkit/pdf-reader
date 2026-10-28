<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;

/** ISO 32000-2, table 11, physical page 59; positive K values select the same decoder. */
final readonly class CcittFaxParameters
{
    public function __construct(
        public int $k = 0,
        public int $columns = 1728,
        public int $rows = 0,
        public bool $endOfLine = false,
        public bool $encodedByteAlign = false,
        public bool $endOfBlock = true,
        public bool $blackIs1 = false,
        public int $damagedRowsBeforeError = 0,
    ) {
        if ($columns < 1 || $columns > PHP_INT_MAX - 7 || $rows < 0 || $damagedRowsBeforeError < 0) {
            throw new ParseException('CCITT requires positive Columns and nonnegative Rows/DamagedRowsBeforeError.');
        }
    }

    public function rowBytes(): int
    {
        return intdiv($this->columns + 7, 8);
    }
}
