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
    ) {
        if ($endOffset <= $section->offset->value || ($stream && $auxiliary !== null)) {
            throw new \InvalidArgumentException('A physical xref has a positive extent and only tables have supplements.');
        }
    }
}
