<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Walk only structure /K edges, preserving malformed, repeated and cyclic branches. */
final class StructureTreeInspector
{
    /** @var list<array<string, mixed>> */
    private array $nodes = [];
    /** @var array<int, string> */
    private array $seen = [];
    /** @var array<int, true> */
    private array $active = [];
    /** @var array<int, true> */
    private array $activeArrays = [];

    /** @param array<int, array{number: int, reference: array{number: string, generation: int}}> $pages */
    public function __construct(
        private readonly InspectionAccess $access,
        private readonly PdfValueInspector $values,
        private readonly RoleMapInspector $roles,
        private readonly AssociatedFilesInspector $files,
        private readonly array $pages,
    ) {
    }

    /** @return list<string> */
    public function read(DictionaryType $root): array
    {
        return $this->children($root->get('K'), null, null, 1);
    }

    /** @param array<string, mixed>|null $inheritedPage
     * @return list<string>
     */
    private function children(?BasicType $source, ?string $parent, ?array $inheritedPage, int $depth): array
    {
        if ($source === null || !$this->access->budget->depth($depth)) {
            return [];
        }
        $value = $this->access->resolved($source, $parent);
        if ($value instanceof NullType) {
            return [];
        }
        if ($value instanceof ArrayType) {
            $identity = spl_object_id($value);
            if (isset($this->activeArrays[$identity])) {
                $id = $this->node($source, $value, $parent, $inheritedPage, $depth, 'cycle');
                return $id === null ? [] : [$id];
            }
            $this->activeArrays[$identity] = true;
            $children = [];
            foreach ($value->items as $item) {
                if (!$this->access->budget->reserve('values')) {
                    break;
                }
                $ids = $this->children($item, $parent, $inheritedPage, $depth + 1);
                array_push($children, ...$ids);
            }
            unset($this->activeArrays[$identity]);
            return $children;
        }
        $id = $this->node($source, $value, $parent, $inheritedPage, $depth);
        return $id === null ? [] : [$id];
    }

    /** @param array<string, mixed>|null $inheritedPage */
    private function node(BasicType $source, ?BasicType $value, ?string $parent, ?array $inheritedPage, int $depth, ?string $forcedKind = null): ?string
    {
        if (!$this->access->budget->reserve('nodes') || !$this->access->budget->reserve('bytes', 1024)) {
            return null;
        }
        $ordinal = count($this->nodes);
        $id = 'node-' . ($ordinal + 1);
        $kind = $forcedKind ?? 'invalid';
        $role = null;
        $namespace = null;
        $page = $inheritedPage === null ? null : [...$inheritedPage, 'inherited' => true];
        $mcid = null;
        $object = null;
        if ($value instanceof IntegerType) {
            $kind = $value->integer() >= 0 ? 'mcid' : 'invalid';
            $mcid = (string) $value->integer();
        } elseif ($value instanceof DictionaryType) {
            $type = $this->access->name($value->get('Type'));
            $roleValue = $this->access->resolved($value->get('S'), $id);
            $kind = match (true) {
                $type === 'MCR' => 'mcr',
                $type === 'OBJR' => 'objr',
                $type === 'StructElem' || $roleValue instanceof NameType => 'element',
                default => 'invalid',
            };
            $role = $roleValue instanceof NameType ? $this->values->inspect($roleValue) : null;
            if ($value->get('Pg') !== null) {
                $pageValue = $this->access->resolved($value->get('Pg'), $id);
                $selected = $pageValue === null ? null : ($this->pages[spl_object_id($pageValue)] ?? null);
                $page = $selected === null ? ['number' => null, 'reference' => $this->access->reference($value->get('Pg')), 'inherited' => false] : [...$selected, 'inherited' => false];
                if ($selected === null) {
                    $this->access->budget->diagnostic('unknown-page', 'The /Pg value does not identify a validated document page.', $id, $this->access->reference($value->get('Pg')));
                }
            }
            $namespace = $this->roles->namespace($value->get('NS'));
            if (isset($namespace['uri']) && is_string($namespace['uri']) && !$this->access->budget->reserve('bytes', strlen($namespace['uri']) * 6)) {
                $namespace = null;
            }
            $marked = $this->access->resolved($value->get('MCID'), $id);
            $mcid = $marked instanceof IntegerType ? (string) $marked->integer() : null;
            $object = $this->access->reference($value->get('Obj'));
            if ($kind === 'mcr' && (!$marked instanceof IntegerType || $marked->integer() < 0)) {
                $this->access->budget->diagnostic('invalid-mcr', 'A marked-content reference requires a non-negative integer /MCID.', $id, $this->access->reference($source));
            }
            if ($kind === 'objr' && $object === null) {
                $this->access->budget->diagnostic('invalid-objr', 'An object reference dictionary requires an indirect /Obj reference.', $id, $this->access->reference($source));
            }
            if ($kind === 'element' && !$roleValue instanceof NameType) {
                $this->access->budget->diagnostic('invalid-structure-role', 'A structure element requires a name-valued /S role.', $id, $this->access->reference($source));
            }
            $identity = spl_object_id($value);
            if (isset($this->active[$identity])) {
                $kind = 'cycle';
            } elseif (isset($this->seen[$identity])) {
                $kind = 'repeated';
            }
        }
        if (in_array($kind, ['invalid', 'cycle', 'repeated'], true)) {
            $this->access->budget->diagnostic('structure-' . $kind, match ($kind) {
                'cycle' => 'The structure children contain a cycle; this edge was not traversed again.',
                'repeated' => 'The structure element occurs more than once; this edge was not traversed again.',
                default => 'A structure child does not have a recognized source structure shape.',
            }, $id, $this->access->reference($source));
        }
        $this->nodes[] = ['id' => $id, 'parentId' => $parent, 'kind' => $kind, 'reference' => $this->access->reference($source), 'role' => $role, 'namespace' => $namespace, 'page' => $page, 'mcid' => $mcid, 'objectReference' => $object, 'properties' => $this->values->inspect($value ?? $source), 'children' => []];
        if ($value instanceof DictionaryType && !in_array($kind, ['cycle', 'repeated'], true)) {
            $identity = spl_object_id($value);
            $this->seen[$identity] = $id;
            $this->active[$identity] = true;
            $this->files->associated($value, ['kind' => 'structure', 'id' => $id, 'reference' => $this->access->reference($source)]);
            if ($kind === 'element') {
                $this->nodes[$ordinal]['children'] = $this->children($value->get('K'), $id, $page, $depth + 1);
            }
            unset($this->active[$identity]);
        }
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }
}
