<?php

declare(strict_types=1);

namespace PDF\Reading\Filters;

use PDF\Reading\ReadBudget;

/** A decoding stage accounts for every byte it produces, including intermediate output. */
interface Decoder
{
    public function decode(string $bytes, ReadBudget $budget): string;
}
