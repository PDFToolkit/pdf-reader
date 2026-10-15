<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringType;

/** The latest physical trailer and original bytes, before inheritance or decryption. */
final readonly class SourceRevision
{
    public function __construct(public FileBytes $source, public DictionaryType $trailer, private SourceStringSpans $strings, private ReadBudget $budget)
    {
    }

    public function span(StringType $value): ?SourceStringSpan
    {
        return $this->strings->of($value);
    }

    public function excluding(SourceStringSpan $span): string
    {
        $size = strlen($this->source->bytes);
        if ($span->end > $size) {
            throw new ParseException('An excluded source span must be inside the original file.');
        }
        $this->budget->retained($size - ($span->end - $span->start));
        return substr($this->source->bytes, 0, $span->start) . substr($this->source->bytes, $span->end);
    }
}
