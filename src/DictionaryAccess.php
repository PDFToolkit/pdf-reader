<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Typed access to structural entries, with an explicitly supplied resolver. */
final readonly class DictionaryAccess
{
    public function __construct(private DictionaryType $dictionary, private ?ObjectResolver $resolver = null)
    {
    }

    public function value(string $key): ?BasicType
    {
        $value = $this->dictionary->get($key);
        return $value !== null && $this->resolver !== null ? $this->resolver->resolve($value) : $value;
    }

    public function integer(string $key, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        $value = $this->value($key);
        if (!$value instanceof IntegerType || $value->integer() < $minimum || $value->integer() > $maximum) {
            throw new ParseException('A structural integer is missing, indirect or outside its permitted range.');
        }
        return $value->integer();
    }

    public function hasName(string $key, string $name): bool
    {
        $value = $this->value($key);
        return $value instanceof NameType && $value->value === $name;
    }
}
