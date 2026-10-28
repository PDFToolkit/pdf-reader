<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

/** Colour interpretation is metadata, never an implicit rendering conversion. */
final readonly class ColorInformation
{
    /**
     * @param list<int|float> $parameters
     * @param list<ColorSpecification> $specifications
     */
    public function __construct(public string $space = 'Unknown', public ?int $enumeratedSpace = null, public ?string $iccProfile = null, public array $parameters = [], public array $specifications = [], public ?int $selectedSpecification = null)
    {
    }
}
