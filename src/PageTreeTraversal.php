<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;

/** Operation-scoped state for iterative depth-first traversal and bottom-up counts. */
final class PageTreeTraversal
{
    /** @var array<string, true> */
    private array $seen = [];
    /** @var list<ReadPage> */
    private array $pages = [];
    private readonly PageAttributeReader $attributes;

    public function __construct(private readonly ReadDocument $document, private readonly PageTreeLimits $limits)
    {
        $this->attributes = new PageAttributeReader($document);
    }

    public function read(ObjectReference $root): ReadPageTree
    {
        // State is reset per invocation; a reader never shares visitation state across documents.
        $this->seen = $this->pages = [];
        $frame = $this->visit($root, null, new PageInheritance(), 1);
        if (!$frame instanceof PageTreeFrame) {
            throw new ParseException('The page-tree root must be a Pages node.');
        }
        $stack = [$frame];
        while ($stack !== []) {
            $parent = $stack[count($stack) - 1];
            $kid = $parent->next();
            if ($kid !== null) {
                $child = $this->visit($kid, $parent->reference, $parent->inheritance, count($stack) + 1);
                if ($child === null) {
                    $parent->addPages(1);
                } else {
                    $stack[] = $child;
                }
                continue;
            }
            $count = $parent->completedCount();
            array_pop($stack);
            if ($stack !== []) {
                $stack[count($stack) - 1]->addPages($count);
            }
        }
        return new ReadPageTree($this->document, $root, ...$this->pages);
    }

    private function visit(ObjectReference $reference, ?ObjectReference $parent, PageInheritance $ancestor, int $depth): ?PageTreeFrame
    {
        $key = (string) $reference->identifier;
        if ($depth > $this->limits->maxDepth || count($this->seen) >= $this->limits->maxNodes) {
            throw new ParseException('The page tree exceeds its depth or node limit.');
        }
        if (isset($this->seen[$key])) {
            throw new ParseException('The page tree contains a cycle or a repeated node.');
        }
        $this->seen[$key] = true;
        $dictionary = $this->document->resolve($reference);
        if (!$dictionary instanceof DictionaryType) {
            throw new ParseException('A page-tree reference must resolve to a dictionary.');
        }
        $actualParent = $dictionary->get('Parent');
        if ($parent === null) {
            if ($actualParent !== null && !$this->document->resolve($actualParent) instanceof NullType) {
                throw new ParseException('The page-tree root must not have a Parent.');
            }
        } elseif (!$actualParent instanceof ObjectReference || (string) $actualParent->identifier !== (string) $parent->identifier) {
            throw new ParseException('A page-tree child must reference its immediate Parent.');
        }
        $access = new DictionaryAccess($dictionary, $this->document);
        $inheritance = $this->attributes->read($dictionary, $ancestor);
        if ($access->hasName('Type', 'Page')) {
            if ($parent === null) {
                throw new ParseException('The page-tree root must be a Pages node.');
            }
            if (count($this->pages) >= $this->limits->maxPages) {
                throw new ParseException('The page tree exceeds its page limit.');
            }
            $this->pages[] = new ReadPage($this->document, $reference, $dictionary, $inheritance->effective());
            return null;
        }
        if (!$access->hasName('Type', 'Pages')) {
            throw new ParseException('A page-tree node must have Type Page or Pages.');
        }
        $count = $access->integer('Count', 0, $this->limits->maxPages);
        $kids = $access->value('Kids');
        if (!$kids instanceof ArrayType) {
            throw new ParseException('A Pages node requires a Kids array.');
        }
        if (count($kids) > $this->limits->maxNodes - count($this->seen)) {
            throw new ParseException('The page-tree child list exceeds the remaining node limit.');
        }
        $references = [];
        foreach ($kids as $kid) {
            if (!$kid instanceof ObjectReference) {
                throw new ParseException('Every Kids item must be an indirect reference.');
            }
            $references[] = $kid;
        }
        return new PageTreeFrame($reference, $inheritance, $count, ...$references);
    }
}
