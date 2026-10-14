<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Distinguishes an intentionally unsupported mechanism from malformed syntax. */
class UnsupportedPdfFeature extends ParseException
{
}
