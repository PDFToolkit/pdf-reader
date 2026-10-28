<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ParseException;

/** A malformed coding row; only this failure category may invoke PDF's explicit damaged-row recovery. */
final class CcittFaxRowException extends ParseException
{
}
