<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** A resource ceiling is not a reason to try another decoder. */
final class CodecLimit extends ParseException
{
}
