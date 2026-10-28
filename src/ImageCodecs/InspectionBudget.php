<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Cumulative native preflight work and conservative retained/intermediate bytes. @internal */
final class InspectionBudget
{
    private int $work = 0;
    private int $memory = 0;

    public function __construct(private readonly CodecRequest $request)
    {
        // Supplied bytes, a body slice and a possible global/page concatenation.
        $this->retain(3 * (strlen($request->bytes) + strlen($request->globals ?? '')));
        $this->work(strlen($request->bytes) + strlen($request->globals ?? ''));
    }

    public function work(int $units): void
    {
        if ($units < 0 || $units > $this->request->limits->maxWork - $this->work) {
            throw new CodecLimit('Native image inspection and plane work exceed their cumulative limit.');
        }
        $this->work += $units;
    }

    public function retain(int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->remainingMemory()) {
            throw new CodecLimit('Native image inspection state exceeds its memory limit.');
        }
        $this->memory += $bytes;
    }

    public function remainingMemory(): int
    {
        return $this->request->limits->maxMemoryBytes - $this->memory;
    }
}
