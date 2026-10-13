<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\DictionaryType;

/** A page view bound to its source document, with its original dictionary intact. */
final readonly class ReadPage
{
    public function __construct(
        public ReadDocument $document,
        public ObjectReference $reference,
        public DictionaryType $dictionary,
        public PageAttributes $attributes,
    ) {
    }
}
