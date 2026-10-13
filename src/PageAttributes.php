<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

/** The four inheritable table 30 values, after inheritance and page-local defaults. */
final readonly class PageAttributes
{
    public function __construct(
        public DictionaryType $resources,
        public Rectangle $mediaBox,
        public Rectangle $cropBox,
        public IntegerType $rotate,
    ) {
        if ($rotate->integer() % 90 !== 0) {
            throw new \InvalidArgumentException('Page rotation must be a multiple of ninety degrees.');
        }
    }

    /** A derived orientation; the original PDF integer remains in rotate. */
    public function normalizedRotation(): int
    {
        return (($this->rotate->integer() % 360) + 360) % 360;
    }
}
