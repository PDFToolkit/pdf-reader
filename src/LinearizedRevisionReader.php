<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** ISO 32000-2 Annex F.3: recognize bounded physical parts without interpreting network hints. */
final readonly class LinearizedRevisionReader
{
    public function __construct(
        private FileBytes $source,
        private ReaderLimits $limits,
        private ReadBudget $budget,
        private CrossReferenceReader $references,
        private RevisionBoundaryReader $boundaries,
        private ?SourceStringSpans $sourceStrings = null,
        private StructuralPolicy $structuralPolicy = StructuralPolicy::Compatible,
    ) {
    }

    public function group(PhysicalCrossReference $first): PhysicalCrossReference
    {
        $cursor = new ByteCursor($this->source->bytes);
        $cursor->skipWhitespaceAndComments();
        $parameters = (new IndirectObjectReader($this->source, $this->limits, $this->budget, $this->sourceStrings, $this->structuralPolicy))->readPhysical($cursor->position());
        $dictionary = $parameters->object->value;
        $version = $dictionary instanceof DictionaryType ? $dictionary->get('Linearized') : null;
        if (!$dictionary instanceof DictionaryType || !$version instanceof NumericType || $version->value != 1
            || $parameters->object->identifier->generationNumber->value !== 0
            || $parameters->span->end > $first->section->offset->value) {
            throw new UnsupportedPdfFeature('A forward Prev is permitted only for the two sections of a recognized initial linearized revision.');
        }
        $fields = new DictionaryAccess($dictionary);
        $length = $fields->integer('L', $first->endOffset + 1, strlen($this->source->bytes));
        $fields->integer('O', 1, $this->limits->maxObjects - 1);
        $pages = $fields->integer('N', 1, $this->limits->maxObjects);
        $fields->integer('E', $first->endOffset, $length - 1);
        $fields->integer('T', 1, $length - 1);
        if ($dictionary->has('P')) {
            $fields->integer('P', 0, $pages - 1);
        }
        $hints = $dictionary->get('H');
        if (!$hints instanceof ArrayType || !in_array(count($hints), [2, 4], true)) {
            throw new ParseException('A linearization dictionary requires one or two direct hint ranges.');
        }
        for ($index = 0; $index < count($hints); $index += 2) {
            $start = $hints->items[$index];
            $count = $hints->items[$index + 1];
            if (!$start instanceof IntegerType || !$count instanceof IntegerType
                || $start->integer() < $first->endOffset || $start->integer() >= $length
                || $count->integer() < 1 || $count->integer() > $length - $start->integer()) {
                throw new ParseException('A linearization hint range must stay inside its initial revision.');
            }
        }
        $offset = $first->section->previous?->value;
        if ($offset === null || $offset <= $first->endOffset || $offset >= $length) {
            throw new ParseException('A linearized first-page section points forward to its main section.');
        }
        $main = $this->references->readPhysical($offset);
        if ((substr($this->source->bytes, $offset, 4) !== 'xref' && !ctype_digit(substr($this->source->bytes, $offset, 1)))
            || $main->section->previous !== null || $main->endOffset >= $length
            || (new DictionaryAccess($main->section->trailer))->integer('Size', 1, $this->limits->maxObjects)
                > (new DictionaryAccess($first->section->trailer))->integer('Size', 1, $this->limits->maxObjects)) {
            throw new ParseException('The main linearized section terminates the initial Prev chain and retains its object range.');
        }
        return new PhysicalCrossReference(
            $first->section,
            $first->endOffset,
            $first->stream,
            $first->auxiliary,
            new LinearizedRevisionParts($main, $this->boundaries->firstPageFooter($first), $parameters->span, $length)
        );
    }
}
