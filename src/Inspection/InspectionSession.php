<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;
use PDF\Reading\ReadDocument;
use PDF\Syntax\Object\DictionaryType;

/** All caches and traversal state end with one immutable result. */
final class InspectionSession
{
    private InspectionBudget $budget;
    private InspectionAccess $access;
    private PdfValueInspector $values;
    private RoleMapInspector $roles;
    private AssociatedFilesInspector $files;

    public function __construct(private readonly ReadDocument $document, private readonly InspectionLimits $limits)
    {
        $this->budget = new InspectionBudget($limits);
        $this->access = new InspectionAccess($document, $this->budget);
        $this->values = new PdfValueInspector($this->access);
        $this->roles = new RoleMapInspector($this->access, $this->values);
        $this->files = new AssociatedFilesInspector($this->access, $this->values);
    }

    public function inspect(): DocumentInspection
    {
        $catalog = $this->document->catalog();
        $rootSource = $catalog->get('StructTreeRoot');
        $root = $this->access->dictionary($rootSource);
        if ($rootSource !== null && $root === null) {
            $this->budget->diagnostic('invalid-structure-root', 'The structure tree root does not resolve to a dictionary.', reference: $this->access->reference($rootSource));
        }
        $pages = [];
        $pageIndex = [];
        try {
            $tree = (new PageTreeReader($this->document, new PageTreeLimits(maxDepth: $this->limits->maxDepth, maxNodes: $this->limits->maxNodes, maxPages: $this->limits->maxPages)))->read();
            foreach ($tree as $index => $page) {
                if (!$this->budget->reserve('bytes', 256)) {
                    break;
                }
                $reference = $this->access->reference($page->reference);
                if ($reference === null) {
                    throw new \LogicException('A validated page requires its reference.');
                }
                $pageIndex[spl_object_id($page->dictionary)] = ['number' => $index + 1, 'reference' => $reference];
                $pages[] = ['number' => $index + 1, 'reference' => $reference, 'properties' => $this->values->inspect($page->dictionary)];
                $this->files->associated($page->dictionary, ['kind' => 'page', 'id' => 'page-' . ($index + 1), 'reference' => $reference]);
            }
        } catch (\PDF\Reading\ParseException | \PDF\Reading\UnsupportedPdfFeature | \InvalidArgumentException $exception) {
            $this->budget->limited('pages-unavailable', 'The page tree could not be validated within the reader and inspection limits.');
        }
        $this->files->associated($catalog, ['kind' => 'catalog', 'id' => 'catalog', 'reference' => $this->access->reference($this->document->trailer()->get('Root'))]);
        $structure = new StructureTreeInspector($this->access, $this->values, $this->roles, $this->files, $pageIndex);
        if ($root instanceof DictionaryType) {
            $this->roles->root($root);
        }
        $roots = $root === null ? [] : $structure->read($root);
        $names = $this->access->dictionary($catalog->get('Names'));
        $this->files->embedded($names?->get('EmbeddedFiles'));
        // Structural rows have first use of the budgets. The broad object graph is expanded last.
        $information = [
            'version' => $this->access->name($catalog->get('Version')) ?? $this->document->header()->version->value,
            'tagged' => $root !== null,
            'catalogReference' => $this->access->reference($this->document->trailer()->get('Root')),
            'catalog' => $this->values->inspect($catalog),
            'structureRootReference' => $this->access->reference($rootSource),
            'structureRoot' => $root === null ? null : $this->values->inspect($root),
            'authenticatedPermissions' => $this->document->permissions()?->effectiveMask(),
        ];
        $objects = $this->objects();
        return new DocumentInspection($information, $pages, $roots, $structure->nodes(), $objects, $this->roles->mappings(), $this->files->files(), $this->budget->diagnostics(), $this->budget->truncated);
    }

    /** @return list<array<string, mixed>> */
    private function objects(): array
    {
        $objects = [];
        for ($index = 0; ($reference = $this->access->queued($index)) !== null; ++$index) {
            $id = $this->access->identity($reference);
            $status = 'available';
            $value = null;
            if ($this->access->missing($reference)) {
                $status = 'missing';
                $this->budget->diagnostic('missing-reference', 'The reference is missing, free, unavailable or has a different generation.', reference: $this->access->reference($reference));
            } else {
                try {
                    // One physical object per row: a chain remains a series of visible references.
                    $value = $this->values->inspect($this->document->object($reference->identifier));
                    if ($value->type === 'truncated') {
                        $status = 'omitted';
                    }
                } catch (\PDF\Reading\ParseException | \PDF\Reading\UnsupportedPdfFeature | \InvalidArgumentException $exception) {
                    $status = 'failed';
                    $this->budget->diagnostic('unreadable-object', 'The object could not be read within the configured reader constraints.', reference: $this->access->reference($reference));
                }
            }
            $objects[] = ['id' => $id, 'reference' => $this->access->reference($reference), 'status' => $status, 'value' => $value];
        }
        return $objects;
    }
}
