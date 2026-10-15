<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;

/** EC3 issues246/403: direct common entries and prohibited xref-stream associations. */
final readonly class CrossReferenceDictionaryValidation
{
    public function __construct(private ReaderLimits $limits)
    {
    }

    public function validate(DictionaryType $dictionary): void
    {
        foreach (['F', 'Metadata', 'AF'] as $key) {
            $value = $dictionary->get($key);
            if ($value !== null && !$value instanceof NullType) {
                throw new ParseException('A PDF 2.0 cross-reference stream cannot contain ' . $key . '.');
            }
        }
        foreach (['Length', 'Filter', 'DecodeParms', 'F', 'FFilter', 'FDecodeParms', 'DL'] as $key) {
            $value = $dictionary->get($key);
            if ($value !== null) {
                $this->direct($value, 1);
            }
        }
    }

    private function direct(BasicType $value, int $depth): void
    {
        if ($depth > $this->limits->maxDepth) {
            throw new ParseException('Cross-reference direct-value validation exceeds the nesting limit.');
        }
        if ($value instanceof ObjectReference) {
            throw new ParseException('PDF 2.0 common cross-reference-stream entries require recursively direct values.');
        }
        if ($value instanceof ArrayType) {
            foreach ($value->items as $item) {
                $this->direct($item, $depth + 1);
            }
        } elseif ($value instanceof DictionaryType) {
            foreach ($value->entries() as $entry) {
                $this->direct($entry->value, $depth + 1);
            }
        }
    }
}
