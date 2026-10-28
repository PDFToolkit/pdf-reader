<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\EntryKind;
use PDF\Reading\CrossReference\Index;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;

/** Bounded physical inspection of a chronological Prev chain, separate from authenticated reading. */
final readonly class RevisionHistoryReader
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits(), private StructuralPolicy $structuralPolicy = StructuralPolicy::Compatible)
    {
    }

    public function read(FileBytes $source): ReadRevisionHistory
    {
        $length = strlen($source->bytes);
        if ($length > $this->limits->maxFileBytes) {
            throw new ParseException('The PDF source exceeds the file-byte limit.');
        }
        if (!preg_match('/\A%PDF-(1\.[0-7]|2\.0)(?:\r\n|\r|\n)/', $source->bytes, $header)) {
            throw new UnsupportedPdfFeature('A physical history requires a PDF 1.0–1.7 or 2.0 header.');
        }
        $tail = substr($source->bytes, -min($length, $this->limits->maxTokenBytes));
        if (!preg_match('/(?:\A|[\r\n])startxref[\x00\x09\x0a\x0c\x0d\x20]+([0-9]+)[\x00\x09\x0a\x0c\x0d\x20]+%%EOF[\x00\x09\x0a\x0c\x0d\x20]*\z/', $tail, $footer)) {
            throw new ParseException('The final physical startxref and EOF are missing or exceed the search limit.');
        }
        $budget = new ReadBudget($this->limits);
        $offset = (new SyntaxParser(new ByteCursor($footer[1]), $this->limits, $budget))->unsignedInteger();
        $decoder = new StreamDecoder($this->limits);
        $strings = new SourceStringSpans($budget);
        $reader = new CrossReferenceReader($source, $this->limits, $budget, $decoder, $strings, $this->structuralPolicy);
        $boundaryReader = new RevisionBoundaryReader($source, $this->limits, $budget);
        $linearizedReader = new LinearizedRevisionReader($source, $this->limits, $budget, $reader, $boundaryReader, $strings, $this->structuralPolicy);
        $physical = [];
        $newerSize = null;
        do {
            if (count($physical) >= $this->limits->maxRevisions) {
                throw new ParseException('The physical revision count exceeds its limit.');
            }
            $reference = $reader->readPhysical($offset);
            if (substr($source->bytes, $offset, 4) !== 'xref' && !ctype_digit(substr($source->bytes, $offset, 1))) {
                throw new ParseException('A physical xref offset must begin its actual table or indirect object.', $offset);
            }
            $size = (new DictionaryAccess($reference->section->trailer))->integer('Size', 1, $this->limits->maxObjects);
            if ($newerSize !== null && $size > $newerSize) {
                throw new ParseException('Trailer Size cannot shrink across physical revisions.', $offset);
            }
            $newerSize = $size;
            $previous = $reference->section->previous?->value;
            if ($previous !== null && $previous >= $offset) {
                $reference = $linearizedReader->group($reference);
                $previous = null;
            }
            $physical[] = $reference;
            $offset = $previous;
        } while ($offset !== null);
        $physical = array_reverse($physical);
        $boundaries = [];
        $revisions = [];
        $sections = [];
        $trailerEntries = [];
        $objectExtents = [];
        foreach ($physical as $ordinal => $reference) {
            if ($reference->linearized !== null) {
                array_unshift($sections, $reference->linearized->main->section);
                foreach ($reference->linearized->main->section->trailer->entries() as $entry) {
                    $trailerEntries[$entry->key->value] = $entry;
                }
            }
            array_unshift($sections, $reference->section);
            foreach ($reference->section->trailer->entries() as $entry) {
                $trailerEntries[$entry->key->value] = $entry;
            }
            $index = new Index(...$sections);
            $budget->retained(count($index->entries()) * 32);
            $effectiveTrailer = new DictionaryType(...array_values($trailerEntries));
            // Used only to locate indexed objects following a stream xref. The returned graph below
            // is a different, prefix-bounded graph, so future bytes can never escape into a revision.
            $physicalGraph = new ReadDocument(
                $source,
                new FileHeader($header[1]),
                $effectiveTrailer,
                $index,
                $this->limits,
                $budget,
                $decoder,
                sourceStrings: $strings,
                structuralPolicy: $this->structuralPolicy
            );
            $boundary = $boundaryReader->read($reference, $physicalGraph);
            $boundaries[] = $boundary;
            if ($ordinal === count($physical) - 1 && $boundary->maximumEndOffset !== $length) {
                throw new ParseException('The latest physical EOF must account for the complete supplied source.');
            }
            $priorEnd = $ordinal === 0 ? 0 : $boundaries[$ordinal - 1]->maximumEndOffset;
            if ($reference->section->offset->value < $priorEnd
                || ($reference->auxiliary !== null && ($reference->auxiliary->section->offset->value < $priorEnd
                    || $reference->auxiliary->endOffset > $reference->section->offset->value))) {
                throw new ParseException('A primary xref and its hybrid supplement belong to the same nonoverlapping physical revision.');
            }
            $budget->retained($boundary->endOffset);
            $prefix = new FileBytes(substr($source->bytes, 0, $boundary->endOffset));
            $raw = new ReadDocument(
                $prefix,
                new FileHeader($header[1]),
                $effectiveTrailer,
                $index,
                $this->limits,
                $budget,
                $decoder,
                physicalRevision: new SourceRevision($prefix, $reference->section->trailer, $strings, $budget),
                sourceStrings: $strings,
                structuralPolicy: $this->structuralPolicy
            );
            $knownPhysical = array_slice($physical, 0, $ordinal + 1);
            $knownBoundaries = array_slice($boundaries, 0, $ordinal + 1);
            foreach ($this->validateObjectExtents($raw, $source, $boundary, $knownPhysical, $knownBoundaries) as $span) {
                $known = $objectExtents[$span->start] ?? null;
                if ($known !== null && ($known->end !== $span->end || (string) $known->identifier !== (string) $span->identifier)) {
                    throw new ParseException('A historical source extent cannot acquire a different object identity.');
                }
                $objectExtents[$span->start] = $span;
            }
            $this->validatePhysicalCoverage($source, $boundary, array_values($objectExtents), $knownPhysical, $knownBoundaries);
            $revisions[] = new ReadRevision($ordinal, $reference, $boundary, $raw, $source, $budget);
        }
        $first = array_shift($revisions) ?? throw new \LogicException('A physical history requires its latest xref.');
        return new ReadRevisionHistory($source, $first, ...$revisions);
    }

    /**
     * @param list<PhysicalCrossReference> $physical
     * @param list<RevisionBoundary> $boundaries
     * @return list<SourceObjectSpan>
     */
    private function validateObjectExtents(ReadDocument $raw, FileBytes $source, RevisionBoundary $boundary, array $physical, array $boundaries): array
    {
        $spans = [];
        foreach ($raw->crossReferences()->entries() as $entry) {
            if ($entry->kind !== EntryKind::Uncompressed) {
                continue;
            }
            if ($entry->firstField >= $boundary->eofOffset || !ctype_digit(substr($source->bytes, $entry->firstField, 1))) {
                throw new ParseException('An ordinary object must begin at its exact offset inside its own revision prefix.');
            }
            $identifier = new ObjectIdentifier(new ObjectNumber($entry->number), new GenerationNumber($entry->generation()));
            $span = $raw->objectSpan($identifier) ?? throw new \LogicException('An ordinary source object retains its physical extent.');
            if ($span->end > $boundary->eofOffset) {
                throw new ParseException('An ordinary object cannot cross its physical EOF.');
            }
            $spans[] = $span;
        }
        usort($spans, static fn (SourceObjectSpan $a, SourceObjectSpan $b): int => $a->start <=> $b->start);
        $previousEnd = 0;
        $protected = [];
        foreach ($physical as $ordinal => $reference) {
            foreach ($reference->physicalParts() as $part) {
                $protected[] = [$part->section->offset->value, $part->endOffset, $part->stream];
            }
            $firstPageFooter = $reference->linearized?->firstPageFooter;
            if ($firstPageFooter !== null) {
                $protected[] = [$firstPageFooter->footerOffset, $firstPageFooter->eofMarkerEnd, false];
            }
            $protected[] = [$boundaries[$ordinal]->footerOffset, $boundaries[$ordinal]->eofMarkerEnd, false];
        }
        usort($protected, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $position = 0;
        foreach ($spans as $span) {
            if ($span->start < $previousEnd) {
                throw new ParseException('Two effective ordinary objects have overlapping source extents.');
            }
            $previousEnd = $span->end;
            while (isset($protected[$position]) && $protected[$position][1] <= $span->start) {
                ++$position;
            }
            for ($check = $position; isset($protected[$check]) && $protected[$check][0] < $span->end; ++$check) {
                [$start, $end, $stream] = $protected[$check];
                if (!$stream || $start !== $span->start || $end !== $span->end) {
                    throw new ParseException('An ordinary object cannot overlap a cross-reference body or physical footer.');
                }
            }
        }
        return $spans;
    }

    /**
     * @param list<SourceObjectSpan> $objects
     * @param list<PhysicalCrossReference> $physical
     * @param list<RevisionBoundary> $boundaries
     */
    private function validatePhysicalCoverage(FileBytes $source, RevisionBoundary $boundary, array $objects, array $physical, array $boundaries): void
    {
        $extents = [];
        foreach ($objects as $span) {
            $extents[$span->start] = $span->end;
        }
        foreach ($physical as $ordinal => $reference) {
            foreach ($reference->physicalParts() as $part) {
                if (isset($extents[$part->section->offset->value]) && $extents[$part->section->offset->value] !== $part->endOffset) {
                    throw new ParseException('A cross-reference stream has exactly its actual indirect-object extent.');
                }
                $extents[$part->section->offset->value] = $part->endOffset;
            }
            $firstPageFooter = $reference->linearized?->firstPageFooter;
            if ($firstPageFooter !== null) {
                $extents[$firstPageFooter->footerOffset] = $firstPageFooter->eofMarkerEnd;
            }
            $extents[$boundaries[$ordinal]->footerOffset] = $boundaries[$ordinal]->eofMarkerEnd;
        }
        ksort($extents, SORT_NUMERIC);
        $cursor = new ByteCursor($source->bytes, 0, $boundary->endOffset);
        foreach ($extents as $start => $end) {
            if ($cursor->position() > $start) {
                throw new ParseException('Physical objects, cross-references and footers cannot overlap.');
            }
            if ($cursor->position() < $start) {
                $cursor->skipWhitespaceAndComments();
                if ($cursor->position() !== $start) {
                    throw new UnsupportedPdfFeature('Physical history contains unindexed syntax or an extent hidden inside a comment.');
                }
            }
            $cursor->seek($end);
        }
        $cursor->skipWhitespaceAndComments();
        if (!$cursor->eof()) {
            throw new ParseException('The physical revision must end at its own verified EOF interval.');
        }
    }
}
