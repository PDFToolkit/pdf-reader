<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

/** A typed, bounded presentation of parsed PDF values; never a PDF serializer. */
final readonly class InspectionValue implements \JsonSerializable
{
    /** @param array<string, mixed> $fields */
    public function __construct(public string $type, private array $fields = [])
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, ...$this->fields];
    }
}
