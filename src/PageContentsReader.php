<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NullType;

/** Contents is page-local; inspection does not execute any stream filter. */
final readonly class PageContentsReader
{
    public function __construct(private ReadDocument $document, private PageContentLimits $limits = new PageContentLimits())
    {
    }

    public function read(ReadPage $page): PageContents
    {
        if ($page->document !== $this->document) {
            throw new \InvalidArgumentException('Page contents must be read with their source document.');
        }
        $value = (new DictionaryAccess($page->dictionary, $this->document))->value('Contents');
        if ($value === null || $value instanceof NullType) {
            return new PageContents($page);
        }
        if ($value instanceof EncodedStream) {
            return new PageContents($page, $value);
        }
        if (!$value instanceof ArrayType) {
            throw new ParseException('Page Contents must resolve to a stream or an array of streams.');
        }
        if (count($value) > $this->limits->maxStreams) {
            throw new ParseException('Page Contents exceeds its stream-count limit.');
        }
        $streams = [];
        foreach ($value as $item) {
            $stream = $this->document->resolve($item);
            if (!$stream instanceof EncodedStream) {
                throw new ParseException('Every Contents array item must resolve to a stream.');
            }
            $streams[] = $stream;
        }
        return new PageContents($page, ...$streams);
    }
}
