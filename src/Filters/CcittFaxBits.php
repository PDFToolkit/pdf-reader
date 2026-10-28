<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ByteCursor;
use PDF\Reading\ParseException;

/** MSB-first bounded view; lookahead restores position but still consumes work. */
final class CcittFaxBits
{
    private int $position = 0;
    private int $work = 0;
    private int $buffer = 0;
    private int $bufferBits = 0;
    private readonly ByteCursor $cursor;
    private readonly int $start;

    public function __construct(string|ByteCursor $bytes, private readonly CcittFaxLimits $limits)
    {
        $this->cursor = is_string($bytes) ? new ByteCursor($bytes) : $bytes;
        $this->start = $this->cursor->position();
    }

    public function work(): int
    {
        return $this->work;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function remaining(): int
    {
        return $this->cursor->remaining() * 8 + $this->bufferBits;
    }

    /** @phpstan-impure */
    public function read(int $count = 1): int
    {
        if ($count < 1 || $count > 24 || $count > $this->remaining()) {
            throw new CcittFaxRowException('CCITT coding data is truncated.');
        }
        if ($count > $this->limits->maxBitReads - $this->work) {
            throw new ParseException('CCITT exceeds its bit-work budget.');
        }
        $this->work += $count;
        $value = 0;
        for ($index = 0; $index < $count; ++$index, ++$this->position) {
            if ($this->bufferBits === 0) {
                $this->buffer = ord($this->cursor->read(1));
                $this->bufferBits = 8;
            }
            --$this->bufferBits;
            $value = ($value << 1) | (($this->buffer >> $this->bufferBits) & 1);
        }
        return $value;
    }

    public function peek(int $count): ?int
    {
        if ($count > $this->remaining()) {
            return null;
        }
        $position = $this->position;
        $cursor = $this->cursor->position();
        $buffer = $this->buffer;
        $bufferBits = $this->bufferBits;
        try {
            return $this->read($count);
        } finally {
            $this->position = $position;
            $this->cursor->seek($cursor);
            $this->buffer = $buffer;
            $this->bufferBits = $bufferBits;
        }
    }

    public function align(bool $requireZero = true): void
    {
        $count = (8 - $this->position % 8) % 8;
        if ($count > 0 && $this->read($count) !== 0 && $requireZero) {
            throw new CcittFaxRowException('CCITT byte alignment requires zero padding.');
        }
    }

    public function finish(): void
    {
        $this->align();
        // T.6 2.4.1.2 permits zero padding to a fixed block size after EOFB.
        while ($this->remaining() > 0) {
            if ($this->read(min(24, $this->remaining())) !== 0) {
                throw new ParseException('CCITT contains non-padding data after its end marker.');
            }
        }
    }

    public function exhausted(): bool
    {
        return $this->remaining() === 0 || ($this->remaining() < 8 && $this->peek($this->remaining()) === 0);
    }

    public function hasEol(): bool
    {
        $value = $this->peek(12);
        return $value === 0 || $value === 1;
    }

    public function eol(): void
    {
        $zeros = 0;
        while ($this->read() === 0) {
            ++$zeros;
        }
        if ($zeros < 11) {
            throw new CcittFaxRowException('CCITT requires an end-of-line synchronization pattern.');
        }
    }

    /** Finds the next synchronization pattern without treating codec padding or budgets as repair permission. */
    public function recover(int $rowStart): void
    {
        $this->seek($rowStart);
        $zeros = 0;
        for ($searched = 0; $searched < $this->limits->maxRecoveryBits && $this->remaining() > 0; ++$searched) {
            if ($this->read() === 0) {
                ++$zeros;
            } else {
                if ($zeros >= 11) {
                    $this->seek($this->position - 12);
                    return;
                }
                $zeros = 0;
            }
        }
        throw new ParseException('CCITT damaged-row recovery did not find EOL within its bounded search.');
    }

    private function seek(int $position): void
    {
        $this->cursor->seek($this->start + intdiv($position, 8));
        $this->position = intdiv($position, 8) * 8;
        $this->bufferBits = 0;
        if ($position % 8 !== 0) {
            $this->read($position % 8);
        }
    }
}
