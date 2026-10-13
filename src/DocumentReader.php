<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\Index;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;

/** Read PDF 1.0–1.7 bytes; source access and upload policy belong to the application. */
final readonly class DocumentReader
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits())
    {
    }

    public function read(FileBytes $source): ReadDocument
    {
        $length = strlen($source->bytes);
        if ($length > $this->limits->maxFileBytes) {
            throw new ParseException('The PDF source exceeds the file-byte limit.');
        }
        if (!preg_match('/\A%PDF-(1\.[0-7])(?:\r\n|\r|\n)/', $source->bytes, $header)) {
            throw new UnsupportedPdfFeature('A PDF 1.0–1.7 header is required at the beginning of the source.');
        }
        // Bound the footer search independently of the potentially much larger source.
        $tail = substr($source->bytes, -min($length, $this->limits->maxTokenBytes));
        if (!preg_match('/(?:\A|[\r\n])startxref[\x00\x09\x0a\x0c\x0d\x20]+([0-9]+)[\x00\x09\x0a\x0c\x0d\x20]+%%EOF[\x00\x09\x0a\x0c\x0d\x20]*\z/', $tail, $footer)) {
            throw new ParseException('The final startxref and EOF markers are missing or outside the footer search limit.');
        }
        $budget = new ReadBudget($this->limits);
        $offset = (new SyntaxParser(new ByteCursor($footer[1]), $this->limits, $budget))->unsignedInteger();
        $decoder = new StreamDecoder($this->limits);
        $reader = new CrossReferenceReader($source, $this->limits, $budget, $decoder);
        $sections = [];
        $seen = [];
        $trailerEntries = [];
        $newerSize = null;
        do {
            if (isset($seen[$offset]) || count($sections) >= $this->limits->maxRevisions) {
                throw new ParseException('The cross-reference revision chain contains a cycle or exceeds its limit.', $offset);
            }
            $seen[$offset] = true;
            $section = $reader->read($offset);
            $size = (new DictionaryAccess($section->trailer))->integer('Size', 1, $this->limits->maxObjects);
            if ($newerSize !== null && $size > $newerSize) {
                throw new ParseException('Trailer Size cannot shrink across incremental revisions.', $offset);
            }
            $newerSize = $size;
            $sections[] = $section;
            foreach ($section->trailer->entries() as $entry) {
                $trailerEntries[$entry->key->value] ??= $entry;
            }
            $offset = $section->previous?->value;
        } while ($offset !== null);
        /** @var list<Entry> $entries */
        $entries = array_values($trailerEntries);
        $document = new ReadDocument($source, new FileHeader($header[1]), new DictionaryType(...$entries), new Index(...$sections), $this->limits, $budget, $decoder);
        $document->catalog();
        return $document;
    }
}
