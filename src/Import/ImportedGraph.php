<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;

/** A complete native graph, scoped to one source document; no revision or security authority. */
final readonly class ImportedGraph
{
    /** @param non-empty-list<BasicType> $roots
     * @param array<string, IndirectReference> $references Source number:generation within this graph's source document only.
     */
    public function __construct(public array $roots, private array $references)
    {
        if ($roots === []) {
            throw new \InvalidArgumentException('An imported graph needs at least one root.');
        }
    }

    public function root(): BasicType
    {
        if (count($this->roots) !== 1) {
            throw new \LogicException('Select a root explicitly when importing several roots.');
        }
        return $this->roots[0];
    }

    public function reference(ObjectIdentifier $source): ?IndirectReference
    {
        return $this->references[$source->objectNumber->value . ':' . $source->generationNumber->value] ?? null;
    }
}
