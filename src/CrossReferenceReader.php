<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\Entry;
use PDF\Reading\CrossReference\EntryKind;
use PDF\Reading\CrossReference\Section;
use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;

/** ISO 32000-1, 7.5.4 and 7.5.8: bounded table and stream cross-references. */
final readonly class CrossReferenceReader
{
    public function __construct(private FileBytes $source, private ReaderLimits $limits, private ReadBudget $budget, private StreamDecoder $decoder)
    {
    }

    public function read(int $offset): Section
    {
        if ($offset < 0 || $offset >= strlen($this->source->bytes)) {
            throw new ParseException('A cross-reference offset is outside the file.', $offset);
        }
        $parser = new SyntaxParser(new ByteCursor($this->source->bytes, $offset), $this->limits, $this->budget);
        return $parser->atKeyword('xref') ? $this->table($parser, $offset) : $this->stream($offset);
    }

    private function table(SyntaxParser $parser, int $offset): Section
    {
        $parser->keyword('xref');
        $entries = [];
        while (!$parser->atKeyword('trailer')) {
            $first = $parser->unsignedInteger();
            $count = $parser->unsignedInteger();
            if ($first > $this->limits->maxObjects || $count > $this->limits->maxObjects - $first) {
                throw new ParseException('A cross-reference subsection exceeds the object-number limit.', $offset);
            }
            $this->budget->crossReferences($count);
            for ($index = 0; $index < $count; ++$index) {
                $parser->cursor->skipWhitespaceAndComments();
                $line = $parser->cursor->peek(20);
                if (!preg_match('/\A([0-9]{10}) ([0-9]{5}) ([nf])(?: \n| \r|\r\n)/', $line, $matches)) {
                    throw new ParseException('A traditional cross-reference record must contain twenty bytes.', $parser->cursor->position());
                }
                $parser->cursor->read(20);
                $number = $first + $index;
                if (isset($entries[$number])) {
                    throw new ParseException('Cross-reference subsections overlap.', $offset);
                }
                $kind = $matches[3] === 'n' ? EntryKind::Uncompressed : EntryKind::Free;
                $entry = new Entry($number, $kind, (int) $matches[1], (int) $matches[2]);
                $this->validateEntry($entry, $this->limits->maxObjects, $offset);
                $entries[$number] = $entry;
            }
        }
        $parser->keyword('trailer');
        $trailer = $parser->read();
        if (!$trailer instanceof DictionaryType) {
            throw new ParseException('A trailer must be a dictionary.', $offset);
        }
        $size = (new DictionaryAccess($trailer))->integer('Size', 1, $this->limits->maxObjects);
        foreach ($entries as $entry) {
            if ($entry->number >= $size) {
                throw new ParseException('A cross-reference entry exceeds trailer Size.', $offset);
            }
        }
        $auxiliary = $trailer->get('XRefStm');
        if ($auxiliary !== null && !$auxiliary instanceof NullType) {
            if (!$auxiliary instanceof IntegerType || $auxiliary->integer() < 1 || $auxiliary->integer() >= $offset) {
                throw new ParseException('XRefStm must be a direct offset before its traditional cross-reference table.', $offset);
            }
            // ISO 32000-1, 7.5.8.4: auxiliary stream entries override this table;
            // the stream's Prev is ignored, and the traditional trailer owns the revision.
            $supplement = $this->stream($auxiliary->integer(), true);
            $supplementSize = (new DictionaryAccess($supplement->trailer))->integer('Size', 1, $size);
            foreach ($supplement->entries as $entry) {
                if ($entry->number >= $supplementSize) {
                    throw new ParseException('An auxiliary cross-reference entry exceeds its declared Size.', $offset);
                }
                $entries[$entry->number] = $entry;
            }
        }
        return $this->section($offset, $trailer, array_values($entries));
    }

    private function stream(int $offset, bool $auxiliary = false): Section
    {
        $object = (new IndirectObjectReader($this->source, $this->limits, $this->budget))->read($offset);
        $stream = $object->value;
        if (!$stream instanceof EncodedStream || $object->identifier->generationNumber->value !== 0) {
            throw new ParseException('A cross-reference stream must be a generation-zero stream object.', $offset);
        }
        $fields = new DictionaryAccess($stream->dictionary());
        if (!$fields->hasName('Type', 'XRef')) {
            throw new ParseException('The cross-reference stream Type must be XRef.', $offset);
        }
        $size = $fields->integer('Size', 1, $this->limits->maxObjects);
        $widthArray = $fields->value('W');
        if (!$widthArray instanceof ArrayType || count($widthArray) !== 3) {
            throw new ParseException('A cross-reference stream requires three direct field widths.', $offset);
        }
        $widths = [];
        foreach ($widthArray->items as $width) {
            if (!$width instanceof IntegerType || $width->integer() < 0 || $width->integer() > 8) {
                throw new ParseException('A cross-reference field width exceeds the supported integer range.', $offset);
            }
            $widths[] = $width->integer();
        }
        $rowWidth = array_sum($widths);
        if ($rowWidth === 0) {
            throw new ParseException('Cross-reference records require at least one encoded field.', $offset);
        }
        $indexArray = $fields->value('Index') ?? new ArrayType(new IntegerType(0), new IntegerType($size));
        if (!$indexArray instanceof ArrayType || count($indexArray) % 2 !== 0) {
            throw new ParseException('A cross-reference Index requires pairs of direct integers.', $offset);
        }
        $ranges = [];
        $recordCount = 0;
        $end = 0;
        for ($index = 0; $index < count($indexArray); $index += 2) {
            $first = $indexArray->items[$index];
            $count = $indexArray->items[$index + 1];
            if (!$first instanceof IntegerType || !$count instanceof IntegerType || $first->integer() < $end || $first->integer() > $size || $count->integer() < 0 || $count->integer() > $size - $first->integer()) {
                throw new ParseException('Cross-reference Index ranges are indirect, overlapping or outside Size.', $offset);
            }
            $end = $first->integer() + $count->integer();
            $recordCount += $count->integer();
            $ranges[] = [$first->integer(), $count->integer()];
        }
        $this->budget->crossReferences($recordCount);
        if ($recordCount > intdiv($this->limits->maxDecodedStreamBytes, $rowWidth)) {
            throw new ParseException('Cross-reference bytes exceed the decoded-stream limit.', $offset);
        }
        // Structural entries in an xref stream must be direct, before resolution is available.
        foreach (['Length', 'Filter', 'DecodeParms', 'Prev', 'Size', 'W', 'Index'] as $key) {
            if ($stream->dictionary()->get($key) instanceof ObjectReference) {
                throw new ParseException('Cross-reference structural entries must be direct.', $offset);
            }
        }
        $bytes = $this->decoder->decode($stream, $this->budget);
        if (strlen($bytes) !== $recordCount * $rowWidth) {
            throw new ParseException('Cross-reference bytes do not match Index and W.', $offset);
        }
        $cursor = new ByteCursor($bytes);
        $entries = [];
        foreach ($ranges as [$first, $count]) {
            for ($index = 0; $index < $count; ++$index) {
                $type = $widths[0] === 0 ? 1 : $this->unsignedField($cursor, $widths[0]);
                $firstField = $this->unsignedField($cursor, $widths[1]);
                $secondField = $this->unsignedField($cursor, $widths[2]);
                $kind = match ($type) {
                    0 => EntryKind::Free, 1 => EntryKind::Uncompressed, 2 => EntryKind::Compressed,
                    default => EntryKind::Unavailable,
                };
                $entry = new Entry($first + $index, $kind, $firstField, $secondField);
                $this->validateEntry($entry, $size, $offset);
                $entries[] = $entry;
            }
        }
        if ($stream->dictionary()->has('XRefStm')) {
            throw new ParseException('An xref stream cannot contain a recursive XRefStm entry.', $offset);
        }
        return $auxiliary ? new Section(new ByteOffset($offset), $stream->dictionary(), null, ...$entries)
            : $this->section($offset, $stream->dictionary(), $entries);
    }

    private function unsignedField(ByteCursor $cursor, int $width): int
    {
        $value = 0;
        for ($index = 0; $index < $width; ++$index) {
            $byte = ord($cursor->read(1));
            if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                throw new ParseException('A cross-reference field exceeds the integer range.');
            }
            $value = $value * 256 + $byte;
        }
        return $value;
    }

    private function validateEntry(Entry $entry, int $size, int $offset): void
    {
        if ($entry->kind === EntryKind::Uncompressed && ($entry->number === 0 || $entry->firstField < 1 || $entry->firstField >= strlen($this->source->bytes) || $entry->secondField >= 65535)) {
            throw new ParseException('An in-use cross-reference record is outside the file or generation range.', $offset);
        }
        if ($entry->kind === EntryKind::Free && ($entry->firstField >= $size || $entry->secondField > 65535)) {
            throw new ParseException('A free cross-reference record is outside the object or generation range.', $offset);
        }
        if ($entry->kind === EntryKind::Compressed && ($entry->number === 0 || $entry->firstField < 1 || $entry->firstField >= $size || $entry->secondField >= $this->limits->maxContainerEntries)) {
            throw new ParseException('A compressed cross-reference record is outside the object-stream limits.', $offset);
        }
    }

    /** @param list<Entry> $entries */
    private function section(int $offset, DictionaryType $trailer, array $entries): Section
    {
        $previous = $trailer->get('Prev');
        if ($previous !== null && !$previous instanceof NullType && (!$previous instanceof IntegerType || $previous->integer() < 0 || $previous->integer() >= strlen($this->source->bytes))) {
            throw new ParseException('Prev must be a direct offset within the file.', $offset);
        }
        return new Section(new ByteOffset($offset), $trailer, $previous instanceof IntegerType ? new ByteOffset($previous->integer()) : null, ...$entries);
    }
}
