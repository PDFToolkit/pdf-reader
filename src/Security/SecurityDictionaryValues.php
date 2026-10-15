<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** Typed access never places source bytes, passwords or key material in error messages. */
final readonly class SecurityDictionaryValues
{
    public function __construct(private ReadDocument $document, public DictionaryType $dictionary)
    {
    }

    public function value(string $name): ?BasicType
    {
        $value = $this->dictionary->get($name);
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof NullType ? null : $value;
    }

    /** Null is absent, but any real value retains its original direct/indirect form. */
    public function directValue(string $name): ?BasicType
    {
        return $this->value($name) === null ? null : $this->dictionary->get($name);
    }

    public function integer(string $name, ?int $default = null): int
    {
        $value = $this->value($name);
        return $value instanceof IntegerType ? $value->integer()
            : ($value === null && $default !== null ? $default : throw new ParseException('An encryption integer is missing or malformed.'));
    }

    public function name(string $name, ?string $default = null): string
    {
        $value = $this->value($name);
        return $value instanceof NameType ? $value->value
            : ($value === null && $default !== null ? $default : throw new ParseException('An encryption name is missing or malformed.'));
    }

    public function bytes(string $name): string
    {
        $value = $this->value($name);
        return $value instanceof StringType ? $value->value : throw new ParseException('An encryption credential is missing or malformed.');
    }

    public function boolean(string $name, bool $default): bool
    {
        $value = $this->value($name);
        return $value instanceof BooleanType ? $value->value
            : ($value === null ? $default : throw new ParseException('An encryption boolean is malformed.'));
    }
}
