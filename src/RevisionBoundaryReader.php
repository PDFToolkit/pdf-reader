<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\EntryKind;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;

/** Find a footer through actual indexed object extents, never by searching arbitrary stream data. */
final readonly class RevisionBoundaryReader
{
    public function __construct(private FileBytes $source, private ReaderLimits $limits, private ReadBudget $budget)
    {
    }

    public function read(PhysicalCrossReference $reference, ReadDocument $physicalGraph): RevisionBoundary
    {
        $tailReference = $reference->linearized->main ?? $reference;
        $cursor = new ByteCursor($this->source->bytes, $tailReference->endOffset);
        $indexed = [];
        if ($tailReference->stream) {
            foreach ($physicalGraph->crossReferences()->entries() as $entry) {
                if ($entry->kind === EntryKind::Uncompressed && $entry->firstField >= $tailReference->endOffset) {
                    $indexed[$entry->firstField] = $entry;
                }
            }
        }
        do {
            $cursor->skipWhitespaceAndComments();
            $start = $cursor->position();
            $entry = $indexed[$start] ?? null;
            if ($entry === null) {
                break;
            }
            $identifier = new ObjectIdentifier(new ObjectNumber($entry->number), new GenerationNumber($entry->generation()));
            $span = $physicalGraph->objectSpan($identifier) ?? throw new \LogicException('An ordinary indexed object retains its source span.');
            $cursor->seek($span->end);
        } while (true);
        $boundary = $this->footer($start, $reference->section->offset->value);
        if ($reference->linearized !== null) {
            $length = $reference->linearized->declaredLength;
            if (!$boundary->acceptsByteLength($length)) {
                throw new ParseException('Linearized L must identify the complete initial physical EOF interval.');
            }
            return new RevisionBoundary($boundary->footerOffset, $boundary->eofOffset, $boundary->eofMarkerEnd, $length, $boundary->maximumEndOffset);
        }
        return $boundary;
    }

    /** A traditional first-page table has a dummy footer; a first-page xref stream may omit it. */
    public function firstPageFooter(PhysicalCrossReference $reference): ?RevisionBoundary
    {
        $cursor = new ByteCursor($this->source->bytes, $reference->endOffset);
        $parser = new SyntaxParser($cursor, $this->limits, $this->budget);
        if (!$parser->atKeyword('startxref')) {
            if ($reference->stream) {
                return null;
            }
            throw new ParseException('A linearized first-page table requires its dummy footer.', $cursor->position());
        }
        return $this->footer($cursor->position(), 0);
    }

    private function footer(int $start, int $expectedOffset): RevisionBoundary
    {
        $tail = substr($this->source->bytes, $start, $this->limits->maxTokenBytes);
        if (!preg_match('/\Astartxref[\x00\x09\x0a\x0c\x0d\x20]+([0-9]+)[\x00\x09\x0a\x0c\x0d\x20]+%%EOF(?=[\x00\x09\x0a\x0c\x0d\x20]|\z)/', $tail, $match)) {
            throw new ParseException('A physical xref requires a complete footer after its body and any indexed trailing objects.', $start);
        }
        $offset = (new SyntaxParser(new ByteCursor($match[1]), $this->limits, $this->budget))->unsignedInteger();
        if ($offset !== $expectedOffset) {
            throw new ParseException('A physical footer must identify its own cross-reference section.', $start);
        }
        $markerEnd = $start + strlen($match[0]);
        if ($markerEnd < strlen($this->source->bytes) && !str_contains("\0\t\n\f\r ", $this->source->bytes[$markerEnd])) {
            throw new ParseException('The physical EOF marker requires a token boundary.', $markerEnd);
        }
        $maximum = $markerEnd;
        $sourceLength = strlen($this->source->bytes);
        while ($maximum < $sourceLength && str_contains("\0\t\n\f\r ", $this->source->bytes[$maximum])) {
            ++$maximum;
        }
        $lineEnd = $markerEnd;
        while ($lineEnd < $maximum && !str_contains("\r\n", $this->source->bytes[$lineEnd])) {
            ++$lineEnd;
        }
        if ($lineEnd < $maximum) {
            $lineEnd += substr($this->source->bytes, $lineEnd, 2) === "\r\n" ? 2 : 1;
        } else {
            $lineEnd = $markerEnd;
        }
        return new RevisionBoundary($start, $markerEnd - 5, $markerEnd, $lineEnd, $maximum);
    }
}
