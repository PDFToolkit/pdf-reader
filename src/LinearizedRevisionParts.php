<?php

declare(strict_types=1);

namespace PDF\Reading;

/** The two cross-reference sections of one initial revision, not two historical updates. */
final readonly class LinearizedRevisionParts
{
    public function __construct(
        public PhysicalCrossReference $main,
        public ?RevisionBoundary $firstPageFooter,
        public SourceObjectSpan $parameters,
        public int $declaredLength,
    ) {
        if ($main->linearized !== null || $main->section->previous !== null
            || $declaredLength <= $main->endOffset || $parameters->end >= $main->section->offset->value
            || $firstPageFooter !== null && $firstPageFooter->maximumEndOffset > $main->section->offset->value) {
            throw new \InvalidArgumentException('Linearized sections belong to one bounded initial revision.');
        }
    }
}
