<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Diagnostics expose positions and fixed descriptions, never document contents. */
class ParseException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $offset = null)
    {
        parent::__construct($message . ($offset === null ? '' : ' At byte ' . $offset . '.'));
    }
}
