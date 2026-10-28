<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** @internal An explicitly preserved encoded chain; never used for XML metadata or Crypt. */
final readonly class PreservedFilter implements Filter
{
    public function __construct(private NameType $filterName, private ?DictionaryType $decodeParameters)
    {
        if ($filterName->value === 'Crypt') {
            throw new \InvalidArgumentException('Source encryption cannot be preserved as a native stream filter.');
        }
    }

    public function name(): NameType
    {
        return $this->filterName;
    }

    public function parameters(): ?DictionaryType
    {
        return $this->decodeParameters;
    }

    public function encode(string $bytes): string
    {
        return $bytes;
    }
}
