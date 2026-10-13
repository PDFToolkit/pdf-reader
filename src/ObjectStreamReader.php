<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;

/** ISO 32000-1, 7.5.7: each compressed member has an independently bounded view. */
final readonly class ObjectStreamReader
{
    public function __construct(private ReaderLimits $limits, private ReadBudget $budget)
    {
    }

    /** @return list<array{number: int, value: BasicType}> */
    public function read(EncodedStream $stream, string $bytes, ObjectResolver $resolver): array
    {
        $fields = new DictionaryAccess($stream->dictionary(), $resolver);
        if (!$fields->hasName('Type', 'ObjStm')) {
            throw new ParseException('A compressed-object container must have Type ObjStm.');
        }
        $count = $fields->integer('N', 1, $this->limits->maxContainerEntries);
        $first = $fields->integer('First', 0, strlen($bytes));
        $header = new SyntaxParser(new ByteCursor($bytes, 0, $first), $this->limits, $this->budget);
        $members = [];
        $numbers = [];
        $previous = -1;
        for ($index = 0; $index < $count; ++$index) {
            $number = $header->unsignedInteger();
            $relative = $header->unsignedInteger();
            if ($number < 1 || $number >= $this->limits->maxObjects || isset($numbers[$number]) || $relative <= $previous || $relative >= strlen($bytes) - $first) {
                throw new ParseException('Object-stream header numbers or offsets are invalid.');
            }
            $numbers[$number] = true;
            $members[] = [$number, $relative];
            $previous = $relative;
        }
        $header->cursor->skipWhitespaceAndComments();
        if (!$header->cursor->eof()) {
            throw new ParseException('The object-stream header extends beyond its declared pairs.');
        }
        $result = [];
        foreach ($members as $index => [$number, $relative]) {
            $end = isset($members[$index + 1]) ? $first + $members[$index + 1][1] : strlen($bytes);
            $parser = new SyntaxParser(new ByteCursor($bytes, $first + $relative, $end), $this->limits, $this->budget);
            $value = $parser->read();
            $parser->cursor->skipWhitespaceAndComments();
            if (!$parser->cursor->eof() || $value instanceof ObjectReference) {
                throw new ParseException('An object-stream member must contain exactly one non-reference value.');
            }
            $result[] = ['number' => $number, 'value' => $value];
        }
        return $result;
    }
}
