<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** Resolve only the four inheritable attributes, without visiting resource contents. */
final readonly class PageAttributeReader
{
    public function __construct(private ObjectResolver $resolver)
    {
    }

    public function read(DictionaryType $dictionary, PageInheritance $ancestor): PageInheritance
    {
        $resources = $this->value($dictionary, 'Resources');
        if ($resources !== null && !$resources instanceof DictionaryType) {
            throw new ParseException('Page Resources must resolve to a dictionary.');
        }
        $mediaBox = $this->value($dictionary, 'MediaBox');
        $cropBox = $this->value($dictionary, 'CropBox');
        $rotate = $this->value($dictionary, 'Rotate');
        if ($rotate !== null && (!$rotate instanceof IntegerType || $rotate->integer() % 90 !== 0)) {
            throw new ParseException('Page Rotate must resolve to an integer multiple of ninety.');
        }
        return new PageInheritance(
            $resources ?? $ancestor->resources,
            $mediaBox === null ? $ancestor->mediaBox : $this->rectangle($mediaBox),
            $cropBox === null ? $ancestor->cropBox : $this->rectangle($cropBox),
            $rotate ?? $ancestor->rotate,
        );
    }

    private function value(DictionaryType $dictionary, string $name): ?BasicType
    {
        $value = (new DictionaryAccess($dictionary, $this->resolver))->value($name);
        return $value instanceof NullType ? null : $value;
    }

    private function rectangle(BasicType $value): Rectangle
    {
        if (!$value instanceof ArrayType || count($value) !== 4) {
            throw new ParseException('A page box must resolve to four numbers.');
        }
        $coordinates = [];
        foreach ($value as $coordinate) {
            $coordinate = $this->resolver->resolve($coordinate);
            if (!$coordinate instanceof NumericType) {
                throw new ParseException('A page box coordinate must resolve to a number.');
            }
            $coordinates[] = $coordinate;
        }
        // 7.9.5 permits either pair of diagonal corners; preserve the PDF values.
        return new Rectangle(...$coordinates);
    }
}
