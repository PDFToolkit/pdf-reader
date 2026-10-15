<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Explicit targeted structural rules; neither mode claims complete PDF conformance. */
enum StructuralPolicy
{
    case Compatible;
    case Pdf20;
}
