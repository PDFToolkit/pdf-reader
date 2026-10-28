<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** A file specification is data: the application chooses its name/URI/platform policy. */
final readonly class ExternalStreamRequest
{
    public function __construct(
        public StringType|DictionaryType $specification,
        public int $maximumBytes,
        private ?ObjectResolver $resolver = null,
    ) {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('External acquisition requires a positive byte limit.');
        }
    }

    /** Resolve one dictionary entry with the owning document's existing bounds. */
    public function field(string $name): ?BasicType
    {
        $value = $this->specification instanceof DictionaryType ? $this->specification->get($name) : null;
        if ($value instanceof ObjectReference && $this->resolver === null) {
            throw new UnsupportedPdfFeature('An indirect external-file field requires its document resolver.');
        }
        $value = $value === null ? null : ($this->resolver?->resolve($value) ?? $value);
        return $value instanceof NullType ? null : $value;
    }
}
