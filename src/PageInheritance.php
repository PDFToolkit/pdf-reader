<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

/** Partial ancestor values; defaults must wait until a leaf's MediaBox is known. */
final readonly class PageInheritance
{
    public function __construct(
        public ?DictionaryType $resources = null,
        public ?Rectangle $mediaBox = null,
        public ?Rectangle $cropBox = null,
        public ?IntegerType $rotate = null,
    ) {
    }

    public function effective(): PageAttributes
    {
        if ($this->resources === null || $this->mediaBox === null) {
            throw new ParseException('A page requires explicit or inherited Resources and MediaBox.');
        }
        return new PageAttributes($this->resources, $this->mediaBox, $this->cropBox ?? $this->mediaBox, $this->rotate ?? new IntegerType(0));
    }
}
