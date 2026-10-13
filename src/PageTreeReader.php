<?php

declare(strict_types=1);

namespace PDF\Reading;

/** Explicitly validate the source's complete page tree; document opening stays lazy. */
final readonly class PageTreeReader
{
    public function __construct(private ReadDocument $document, private PageTreeLimits $limits = new PageTreeLimits())
    {
    }

    public function read(): ReadPageTree
    {
        $root = $this->document->catalog()->get('Pages');
        if (!$root instanceof ObjectReference) {
            throw new ParseException('The catalog requires an indirect page-tree root.');
        }
        return (new PageTreeTraversal($this->document, $this->limits))->read($root);
    }
}
