<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

/** T.6 table 4: at most five pending pixels, including patterns spanning scanlines. */
final class CcittFaxUncompressed
{
    private bool $active = false;
    private string $pending = '';
    private ?bool $exitWhite = null;

    public function enter(): void
    {
        $this->active = true;
        $this->pending = '';
        $this->exitWhite = null;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function exitWhite(): bool
    {
        return $this->exitWhite ?? true;
    }

    /** White/black pixel, or null when an explicit exit returns to regular coding. */
    public function pixel(CcittFaxBits $bits): ?bool
    {
        if ($this->pending === '' && $this->exitWhite === null) {
            $zeros = 0;
            while ($bits->read() === 0) {
                if (++$zeros > 10) {
                    throw new CcittFaxRowException('CCITT contains an invalid uncompressed code.');
                }
            }
            if ($zeros < 5) {
                $this->pending = str_repeat('0', $zeros) . '1';
            } elseif ($zeros === 5) {
                $this->pending = '00000';
            } else {
                $this->pending = str_repeat('0', $zeros - 6);
                $this->exitWhite = $bits->read() === 0;
            }
        }
        if ($this->pending === '') {
            $this->active = false;
            return null;
        }
        $white = $this->pending[0] === '0';
        $this->pending = substr($this->pending, 1);
        return $white;
    }

    /** A zero-pixel exit at a row boundary precedes EOL/EOFB, not a new row. */
    public function boundary(CcittFaxBits $bits): void
    {
        if (!$this->active || $this->pending !== '') {
            return;
        }
        if ($this->exitWhite !== null) {
            $this->active = false;
        } elseif ($bits->peek(7) === 1) {
            $bits->read(7);
            $this->exitWhite = $bits->read() === 0;
            $this->active = false;
        }
    }
}
