<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\EntryKind;
use PDF\Reading\CrossReference\Index;
use PDF\Syntax\Object\DictionaryType;

/** EC3 issue114: each ObjStm dictionary value is direct or references an uncompressed object. */
final readonly class ObjectStreamDictionaryValidation
{
    public function __construct(private Index $index)
    {
    }

    public function validate(DictionaryType $dictionary): void
    {
        foreach ($dictionary->entries() as $field) {
            $reference = $field->value;
            if (!$reference instanceof ObjectReference) {
                continue;
            }
            $entry = $this->index->entry($reference->identifier->objectNumber->value);
            if ($entry?->kind === EntryKind::Compressed && $reference->identifier->generationNumber->value === 0) {
                throw new ParseException('A PDF 2.0 ObjStm dictionary value cannot reference a compressed object.');
            }
        }
    }
}
