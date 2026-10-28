<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

/** @internal One budget survives all roots in an import, including repeated source references. */
final class ImportBudget
{
    private int $objects = 0;
    private int $values = 0;
    private int $bytes = 0;
    private int $cycles = 0;

    public function __construct(private readonly ImportLimits $limits)
    {
    }

    public function value(int $depth): void
    {
        if ($depth > $this->limits->maxDepth || ++$this->values > $this->limits->maxValues) {
            throw new ImportException('The import depth or value budget was exceeded.');
        }
        $this->bytes(128);
    }

    public function object(): void
    {
        if (++$this->objects > $this->limits->maxObjects) {
            throw new ImportException('The import object budget was exceeded.');
        }
    }

    public function cycle(): void
    {
        if (++$this->cycles > $this->limits->maxCycles) {
            throw new ImportException('The import cycle budget was exceeded.');
        }
    }

    public function bytes(int $bytes): void
    {
        if ($bytes > $this->limits->maxBytes - $this->bytes) {
            throw new ImportException('The import retained-byte budget was exceeded.');
        }
        $this->bytes += $bytes;
    }
}
