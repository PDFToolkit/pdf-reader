<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;

/** Expose mapping declarations without replacing source roles or merging namespace identities. */
final class RoleMapInspector
{
    /** @var array<int, array<string, mixed>> */
    private array $namespaces = [];
    /** @var list<array<string, mixed>> */
    private array $mappings = [];

    public function __construct(private readonly InspectionAccess $access, private readonly PdfValueInspector $values)
    {
    }

    public function root(DictionaryType $root): void
    {
        $this->map($root->get('RoleMap'), 'structure-root', null, 1);
        foreach ($this->access->items($root->get('Namespaces')) as $source) {
            if (!$this->access->budget->reserve('values')) {
                break;
            }
            $this->namespace($source);
        }
    }

    /** @return array<string, mixed>|null */
    public function namespace(?BasicType $source, int $depth = 1): ?array
    {
        if ($source === null || !$this->access->budget->depth($depth)) {
            return null;
        }
        $dictionary = $this->access->dictionary($source);
        if ($dictionary === null) {
            $this->access->budget->diagnostic('invalid-namespace', 'A structure namespace must resolve to a dictionary.', reference: $this->access->reference($source));
            return null;
        }
        $id = spl_object_id($dictionary);
        if (isset($this->namespaces[$id])) {
            return $this->namespaces[$id];
        }
        if (!$this->access->budget->reserve('values') || !$this->access->budget->reserve('bytes', 256)) {
            return null;
        }
        $namespace = ['identity' => $this->access->identity($source), 'uri' => $this->access->text($dictionary->get('NS')), 'reference' => $this->access->reference($source)];
        $this->namespaces[$id] = $namespace;
        $this->map($dictionary->get('RoleMapNS'), $namespace['identity'], $namespace, $depth + 1);
        return $namespace;
    }

    /** @param array<string, mixed>|null $namespace */
    private function map(?BasicType $source, string $owner, ?array $namespace, int $depth): void
    {
        if ($source === null || !$this->access->budget->depth($depth)) {
            return;
        }
        $dictionary = $this->access->dictionary($source);
        if ($dictionary === null) {
            $this->access->budget->diagnostic('invalid-role-map', 'A role mapping entry must resolve to a dictionary.', reference: $this->access->reference($source));
            return;
        }
        foreach ($dictionary->entries() as $entry) {
            $namespaceBytes = isset($namespace['uri']) && is_string($namespace['uri']) ? strlen($namespace['uri']) * 6 : 0;
            if (!$this->access->budget->reserve('values') || !$this->access->budget->reserve('bytes', 512 + $namespaceBytes)) {
                break;
            }
            $this->mappings[] = ['owner' => $owner, 'namespace' => $namespace, 'source' => $this->values->inspect($entry->key), 'target' => $this->values->inspect($entry->value)];
            $target = $this->access->resolved($entry->value);
            if ($namespace !== null && $target instanceof ArrayType && count($target) === 2) {
                $this->namespace($target->items[1], $depth + 1);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function mappings(): array
    {
        return $this->mappings;
    }
}
