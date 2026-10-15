<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** ISO 32000-1, 7.3.8–7.3.10: object boundaries and length-delimited streams. */
final readonly class IndirectObjectReader
{
    public function __construct(private FileBytes $source, private ReaderLimits $limits, private ReadBudget $budget, private ?SourceStringSpans $sourceStrings = null)
    {
    }

    public function read(int $offset, ?ObjectIdentifier $expected = null, ?ObjectResolver $resolver = null): IndirectObject
    {
        if ($offset < 0 || $offset >= strlen($this->source->bytes)) {
            throw new ParseException('An indirect-object offset is outside the file.', $offset);
        }
        $parser = new SyntaxParser(new ByteCursor($this->source->bytes, $offset), $this->limits, $this->budget, $this->sourceStrings);
        $number = $parser->unsignedInteger();
        $generation = $parser->unsignedInteger();
        if ($number < 1 || $number >= $this->limits->maxObjects || $generation >= 65535) {
            throw new ParseException('An indirect-object identifier is outside its permitted range.', $offset);
        }
        $identifier = new ObjectIdentifier(new ObjectNumber($number), new GenerationNumber($generation));
        if ($expected !== null && (string) $expected !== (string) $identifier) {
            throw new ParseException('The indirect object does not match its cross-reference identifier.', $offset);
        }
        $parser->keyword('obj');
        $value = $parser->read();
        if ($value instanceof DictionaryType && $parser->atKeyword('stream')) {
            $parser->keyword('stream');
            $cursor = $parser->cursor;
            while ($cursor->peek() === ' ' || $cursor->peek() === "\t") {
                $cursor->read(1);
            }
            if ($cursor->peek(2) === "\r\n") {
                $cursor->read(2);
            } elseif ($cursor->peek() === "\n") {
                $cursor->read(1);
            } else {
                throw new ParseException('A stream keyword requires LF or CRLF before its data.', $cursor->position());
            }
            $lengthValue = $value->get('Length');
            if ($lengthValue === null) {
                throw new ParseException('A stream requires an explicit Length.', $offset);
            }
            if ((new DictionaryAccess($value))->hasName('Type', 'XRef') && $lengthValue instanceof ObjectReference) {
                throw new ParseException('A cross-reference stream requires a direct Length.', $offset);
            }
            if ($lengthValue instanceof ObjectReference && $resolver === null) {
                throw new ParseException('An indirect stream Length needs its document resolver.', $offset);
            }
            $lengthValue = $resolver?->resolve($lengthValue) ?? $lengthValue;
            if (!$lengthValue instanceof IntegerType || $lengthValue->integer() < 0 || $lengthValue->integer() > $this->limits->maxFileBytes) {
                throw new ParseException('A stream Length is invalid or exceeds the source limit.', $offset);
            }
            $this->budget->retained($lengthValue->integer());
            $bytes = $cursor->read($lengthValue->integer());
            // The extent comes exclusively from Length. Embedded markers remain data.
            if ($cursor->peek(2) === "\r\n") {
                $cursor->read(2);
            } elseif ($cursor->peek() === "\r" || $cursor->peek() === "\n") {
                $cursor->read(1);
            }
            $parser->keyword('endstream');
            $value = new EncodedStream($value, $bytes);
        }
        $parser->keyword('endobj');
        return new IndirectObject($value, $identifier);
    }
}
