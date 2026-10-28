<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\Section;

/** A parsed xref section and its exact lexical end, including a hybrid supplement when present. */
final readonly class PhysicalCrossReference
{
    public function __construct(
        public Section $section,
        public int $endOffset,
        public bool $stream,
        public ?self $auxiliary = null,
        public ?LinearizedRevisionParts $linearized = null,
    ) {
        if ($endOffset <= $section->offset->value || ($stream && $auxiliary !== null)
            || $linearized !== null && ($section->previous?->value !== $linearized->main->section->offset->value
                || $linearized->parameters->end > $section->offset->value
                || $linearized->main->section->offset->value <= $endOffset)) {
            throw new \InvalidArgumentException('A physical xref has a positive extent and only tables have supplements.');
        }
    }

    /** @return list<self> Exact lexical xref bodies, including hybrid and linearized parts. */
    public function physicalParts(): array
    {
        $parts = [$this];
        if ($this->auxiliary !== null) {
            $parts[] = $this->auxiliary;
        }
        if ($this->linearized !== null) {
            array_push($parts, ...$this->linearized->main->physicalParts());
        }
        return $parts;
    }
}
