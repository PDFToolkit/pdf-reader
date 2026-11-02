<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

/** Immutable source inspection, independent of any rendering or HTML derivation. */
final readonly class DocumentInspection implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $document
     * @param list<array<string, mixed>> $pages
     * @param list<string> $roots
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $objects
     * @param list<array<string, mixed>> $roleMappings
     * @param list<array<string, mixed>> $associatedFiles
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public array $document,
        public array $pages,
        public array $roots,
        public array $nodes,
        public array $objects,
        public array $roleMappings,
        public array $associatedFiles,
        public array $diagnostics,
        public bool $truncated,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['schemaVersion' => 1, ...get_object_vars($this)];
    }
}
