<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Exact JP2 colour alternative, including unrecognized method payloads. */
final readonly class ColorSpecification
{
    /** @param list<int|float> $parameters */
    public function __construct(public int $method, public int $precedence, public int $approximation, public string $methodData, public ?int $enumeratedSpace = null, public ?string $iccProfile = null, public array $parameters = [])
    {
    }
}
