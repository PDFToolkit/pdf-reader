<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;

/** MSB-first bounded view; lookahead restores position but still consumes work. */
final class CcittFaxBits
{
    private int $position = 0;
    private int $work = 0;

    public function __construct(private readonly string $bytes, private readonly CcittFaxLimits $limits)
    {
    }

    public function position(): int
    {
        return $this->position;
    }

    public function remaining(): int
    {
        return strlen($this->bytes) * 8 - $this->position;
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
            $value = ($value << 1) | ((ord($this->bytes[intdiv($this->position, 8)]) >> (7 - $this->position % 8)) & 1);
        }
        return $value;
    }

    public function peek(int $count): ?int
    {
        if ($count > $this->remaining()) {
            return null;
        }
        $position = $this->position;
        try {
            return $this->read($count);
        } finally {
            $this->position = $position;
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
        $this->position = $rowStart;
        $zeros = 0;
        for ($searched = 0; $searched < $this->limits->maxRecoveryBits && $this->remaining() > 0; ++$searched) {
            if ($this->read() === 0) {
                ++$zeros;
            } else {
                if ($zeros >= 11) {
                    $this->position -= 12;
                    return;
                }
                $zeros = 0;
            }
        }
        throw new ParseException('CCITT damaged-row recovery did not find EOL within its bounded search.');
    }
}
