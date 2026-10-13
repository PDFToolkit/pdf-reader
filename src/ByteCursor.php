<?php

declare(strict_types=1);

namespace PDF\Reading;

/** A bounded view with absolute offsets; constructing it does not copy a slice. */
final class ByteCursor
{
    private readonly int $start;
    private readonly int $end;
    private int $offset;

    public function __construct(private readonly string $bytes, int $offset = 0, ?int $end = null)
    {
        $length = strlen($bytes);
        $end ??= $length;
        if ($offset < 0 || $end < $offset || $end > $length) {
            throw new \InvalidArgumentException('Invalid PDF byte-view bounds.');
        }
        $this->start = $offset;
        $this->offset = $offset;
        $this->end = $end;
    }

    public function position(): int
    {
        return $this->offset;
    }

    public function remaining(): int
    {
        return $this->end - $this->offset;
    }

    public function peek(int $length = 1): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('A PDF byte count cannot be negative.');
        }
        return substr($this->bytes, $this->offset, min($length, $this->remaining()));
    }

    /** @phpstan-impure */
    public function read(int $length): string
    {
        if ($length < 0 || $length > $this->remaining()) {
            throw new ParseException('The requested bytes exceed the byte-view bounds.', $this->offset);
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    /** @phpstan-impure */
    public function seek(int $offset): void
    {
        if ($offset < $this->start || $offset > $this->end) {
            throw new ParseException('The requested position exceeds the byte-view bounds.', $this->offset);
        }
        $this->offset = $offset;
    }

    /** @phpstan-impure */
    public function skipWhitespaceAndComments(): void
    {
        while (!$this->eof()) {
            $byte = $this->bytes[$this->offset];
            if (str_contains("\0\t\n\f\r ", $byte)) {
                ++$this->offset;
                continue;
            }
            if ($byte !== '%') {
                return;
            }
            do {
                ++$this->offset;
            } while (!$this->eof() && $this->bytes[$this->offset] !== "\r" && $this->bytes[$this->offset] !== "\n");
        }
    }

    public function eof(): bool
    {
        return $this->offset === $this->end;
    }
}
