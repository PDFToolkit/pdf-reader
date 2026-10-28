<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Cumulative accounting belongs to a document read, never to a global registry. */
final class ReadBudget
{
    private int $parsedValues = 0;
    private int $crossReferenceEntries = 0;
    private int $decodedBytes = 0;
    private int $retainedBytes = 0;
    private int $externalRequests = 0;

    public function __construct(private readonly ReaderLimits $limits)
    {
    }

    public function value(): void
    {
        if ($this->parsedValues >= $this->limits->maxParsedValues) {
            throw new ParseException('The cumulative parsed-value limit was exceeded.');
        }
        ++$this->parsedValues;
    }

    public function externalRequest(): void
    {
        if ($this->externalRequests >= $this->limits->maxExternalStreamRequests) {
            throw new ParseException('The cumulative external-stream acquisition limit was exceeded.');
        }
        ++$this->externalRequests;
    }

    public function crossReferences(int $count): void
    {
        if ($count < 0 || $count > $this->limits->maxCrossReferenceEntries - $this->crossReferenceEntries) {
            throw new ParseException('The cumulative cross-reference limit was exceeded.');
        }
        $this->crossReferenceEntries += $count;
    }

    public function decoded(int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->limits->maxTotalDecodedBytes - $this->decodedBytes) {
            throw new ParseException('The cumulative decoded-byte limit was exceeded.');
        }
        $this->decodedBytes += $bytes;
    }

    public function retained(int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->limits->maxRetainedBytes - $this->retainedBytes) {
            throw new ParseException('The cumulative retained-byte limit was exceeded.');
        }
        $this->retainedBytes += $bytes;
    }
}
