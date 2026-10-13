<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\SyntaxParser;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingSyntaxTest extends TestCase
{
    private function parse(string $bytes, ?ReaderLimits $limits = null): BasicType
    {
        return (new SyntaxParser(new ByteCursor($bytes), $limits ?? new ReaderLimits()))->read();
    }

    public function testFundamentalObjectsRetainTheirExplicitTypes(): void
    {
        $parsed = $this->parse("% A comment\r\n[true false null -12 +7 .25 3. /A#20B () <> << /Key [1] >>]");
        self::assertInstanceOf(ArrayType::class, $parsed);
        self::assertInstanceOf(BooleanType::class, $parsed->items[0]);
        self::assertTrue($parsed->items[0]->value);
        self::assertInstanceOf(BooleanType::class, $parsed->items[1]);
        self::assertFalse($parsed->items[1]->value);
        self::assertInstanceOf(NullType::class, $parsed->items[2]);
        self::assertInstanceOf(IntegerType::class, $parsed->items[3]);
        self::assertSame(-12, $parsed->items[3]->integer());
        self::assertInstanceOf(IntegerType::class, $parsed->items[4]);
        self::assertSame(7, $parsed->items[4]->integer());
        self::assertInstanceOf(NumericType::class, $parsed->items[5]);
        self::assertSame(0.25, $parsed->items[5]->value);
        self::assertInstanceOf(NumericType::class, $parsed->items[6]);
        self::assertSame(3.0, $parsed->items[6]->value);
        self::assertInstanceOf(NameType::class, $parsed->items[7]);
        self::assertSame('A B', $parsed->items[7]->value);
        self::assertInstanceOf(LiteralString::class, $parsed->items[8]);
        self::assertSame('', $parsed->items[8]->value);
        self::assertInstanceOf(HexadecimalString::class, $parsed->items[9]);
        self::assertSame('', $parsed->items[9]->value);
        self::assertInstanceOf(DictionaryType::class, $parsed->items[10]);
        self::assertInstanceOf(ArrayType::class, $parsed->items[10]->get('Key'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function literalExamples(): iterable
    {
        yield 'nested parentheses' => ['(a(b(c))d)', 'a(b(c))d'];
        yield 'named escapes' => ['(\\n\\r\\t\\b\\f\\(\\)\\\\)', "\n\r\t\x08\f()\\"];
        yield 'unknown escape ignores slash' => ['(\\q)', 'q'];
        yield 'one two three octal digits' => ['(\\1x\\12x\\1234)', "\1x\nxS4"];
        yield 'octal byte overflow' => ['(\\777\\400)', "\xff\0"];
        yield 'line normalization' => ["(a\rb\r\nc\nd)", "a\nb\nc\nd"];
        yield 'continuation' => ["(a\\\rb\\\r\nc\\\nd)", 'abcd'];
    }

    #[DataProvider('literalExamples')]
    public function testLiteralStringsFollowPdfEscapeAndLineRules(string $source, string $expected): void
    {
        $parsed = $this->parse($source);
        self::assertInstanceOf(LiteralString::class, $parsed);
        self::assertSame($expected, $parsed->value);
    }

    public function testAllByteValuesRoundTripThroughLiteralAndHexadecimalStrings(): void
    {
        $bytes = '';
        for ($i = 0; $i < 256; ++$i) {
            $bytes .= chr($i);
        }
        foreach ([new LiteralString($bytes), new HexadecimalString($bytes)] as $string) {
            $parsed = $this->parse($string->toPdf(new SerializationContext()));
            self::assertInstanceOf($string::class, $parsed);
            self::assertSame($bytes, $parsed->value);
        }
    }

    public function testHexadecimalStringsAllowPdfWhitespaceAndPadAnOddNibble(): void
    {
        $parsed = $this->parse("< 4\t1\n4\f2\r4\0 >");
        self::assertInstanceOf(HexadecimalString::class, $parsed);
        self::assertSame("AB\x40", $parsed->value);
    }

    public function testNamesDecodeEscapedDelimitersAndRetainBytes(): void
    {
        $parsed = $this->parse('/#2F#23#28#29#3C#3E#5B#5D#25#FF');
        self::assertInstanceOf(NameType::class, $parsed);
        self::assertSame("/#()<>[]%\xff", $parsed->value);
        $empty = $this->parse('/');
        self::assertInstanceOf(NameType::class, $empty);
        self::assertSame('', $empty->value);
    }

    public function testIndirectReferencesAreValuesWithinTheirSourceDocument(): void
    {
        $parsed = $this->parse("23 % number\r\n 7 R");
        self::assertInstanceOf(ObjectReference::class, $parsed);
        self::assertSame(23, $parsed->identifier->objectNumber->number());
        self::assertSame(7, $parsed->identifier->generationNumber->number());
        $this->expectException(\LogicException::class);
        $parsed->toPdf(new SerializationContext());
    }

    public function testReferenceLookaheadDoesNotConsumeAdjacentNumbers(): void
    {
        $parser = new SyntaxParser(new ByteCursor('12 34 56 R 78 /N'));
        $first = $parser->read();
        self::assertInstanceOf(IntegerType::class, $first);
        self::assertSame(12, $first->integer());
        self::assertSame(2, $parser->cursor->position());
        $reference = $parser->read();
        self::assertInstanceOf(ObjectReference::class, $reference);
        self::assertSame(34, $reference->identifier->objectNumber->number());
        self::assertSame(56, $reference->identifier->generationNumber->number());
        $last = $parser->read();
        self::assertInstanceOf(IntegerType::class, $last);
        self::assertSame(78, $last->integer());
        $name = $parser->read();
        self::assertInstanceOf(NameType::class, $name);
        self::assertSame('N', $name->value);
    }

    public function testReferencesInsideARecursiveDictionaryDoNotTriggerResolution(): void
    {
        $dictionary = $this->parse('<< /Self 1 0 R /Parent [1 0 R] >>');
        self::assertInstanceOf(DictionaryType::class, $dictionary);
        self::assertInstanceOf(ObjectReference::class, $dictionary->get('Self'));
        self::assertSame('1 0', (string) $dictionary->get('Self')->identifier);
    }

    public function testIntegerBoundsAreCheckedBeforeNativeConversion(): void
    {
        foreach ([PHP_INT_MIN, PHP_INT_MAX, 0] as $expected) {
            $parsed = $this->parse((string) $expected);
            self::assertInstanceOf(IntegerType::class, $parsed);
            self::assertSame($expected, $parsed->integer());
        }
        $parsed = $this->parse('+0000000000007');
        self::assertInstanceOf(IntegerType::class, $parsed);
        self::assertSame(7, $parsed->integer());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSyntax(): iterable
    {
        foreach ([
            'empty input' => '', 'exponent' => '1e2', 'not a number' => '.', 'multiple signs' => '--1',
            'keyword suffix' => 'trueX', 'undefined keyword' => 'undefined', 'integer overflow' => (string) PHP_INT_MAX . '0',
            'negative integer overflow' => (string) PHP_INT_MIN . '0', 'non-finite real' => str_repeat('9', 400) . '.0',
            'unterminated literal' => '(secret', 'trailing escape' => '(secret\\', 'unterminated hex' => '<01',
            'non-hex digit' => '<0G>', 'comment in hexadecimal' => '<00%comment>', 'unterminated array' => '[1',
            'invalid dictionary key' => '<< 1 /A >>', 'missing dictionary value' => '<< /A >>',
            'duplicate decoded names' => '<< /A 1 /#41 2 >>', 'unterminated dictionary' => '<< /A 1',
            'name short escape' => '/A#2', 'name non-hex escape' => '/A#GG', 'name null byte' => '/A#00',
            'generation outside range' => '1 65536 R', 'reserved object number' => '0 0 R', 'unmatched closing bracket' => ']',
        ] as $description => $source) {
            yield $description => [$source];
        }
    }

    #[DataProvider('malformedSyntax')]
    public function testMalformedSyntaxRaisesAControlledException(string $source): void
    {
        $this->expectException(ParseException::class);
        $this->parse($source);
    }

    public function testKeywordBoundariesDoNotMatchLongerNames(): void
    {
        $parser = new SyntaxParser(new ByteCursor(" % comment\r\nendobject endobj /Next"));
        self::assertFalse($parser->atKeyword('endobj'));
        $parser->keyword('endobject');
        self::assertTrue($parser->atKeyword('endobj'));
        $position = $parser->cursor->position();
        self::assertTrue($parser->atKeyword('endobj'));
        self::assertSame($position, $parser->cursor->position());
        $parser->keyword('endobj');
        self::assertInstanceOf(NameType::class, $parser->read());
    }

    public function testUnsignedIntegersRejectSignedAndRealTokens(): void
    {
        foreach (['-1', '+1', '.1', '1.0', '1e2'] as $token) {
            try {
                (new SyntaxParser(new ByteCursor($token)))->unsignedInteger();
                self::fail('Invalid unsigned integer was accepted.');
            } catch (ParseException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, (new SyntaxParser(new ByteCursor('000')))->unsignedInteger());
    }

    public function testCursorUsesAbsolutePositionsAndEnforcesItsView(): void
    {
        $cursor = new ByteCursor('prefixDATAsecret', 6, 10);
        self::assertSame(6, $cursor->position());
        self::assertSame('DATA', $cursor->peek(PHP_INT_MAX));
        self::assertSame('DA', $cursor->read(2));
        self::assertSame(2, $cursor->remaining());
        $cursor->seek(6);
        self::assertSame('DATA', $cursor->read(4));
        self::assertTrue($cursor->eof());
        self::assertSame('', $cursor->peek());
        $this->expectException(ParseException::class);
        $cursor->read(1);
    }

    public function testCursorCannotSeekBeforeItsInitialViewPosition(): void
    {
        $cursor = new ByteCursor('012345', 2, 5);
        $this->expectException(ParseException::class);
        $cursor->seek(1);
    }

    public function testWhitespaceCommentsAndEofAreBounded(): void
    {
        $cursor = new ByteCursor("X\0\t\n\f\r % ignored\r\n 42ignored", 1, 21);
        $cursor->skipWhitespaceAndComments();
        self::assertSame('42', $cursor->peek(2));
        self::assertSame('42', $cursor->read(2));
        self::assertTrue($cursor->eof());
        $comment = new ByteCursor('% no line ending');
        $comment->skipWhitespaceAndComments();
        self::assertTrue($comment->eof());
    }

    /** @return iterable<string, array{string, ReaderLimits}> */
    public static function limitedSyntax(): iterable
    {
        yield 'array nesting' => ['[[[0]]]', new ReaderLimits(maxDepth: 3)];
        yield 'literal nesting' => ['(((x)))', new ReaderLimits(maxDepth: 2)];
        yield 'scalar token' => ['12345', new ReaderLimits(maxTokenBytes: 4)];
        yield 'name token' => ['/#41#42', new ReaderLimits(maxTokenBytes: 6)];
        yield 'literal raw escapes' => ['(\\1\\2)', new ReaderLimits(maxTokenBytes: 5)];
        yield 'hex whitespace' => ['<0    0>', new ReaderLimits(maxTokenBytes: 6)];
        yield 'array entries' => ['[0 1 2]', new ReaderLimits(maxContainerEntries: 2)];
        yield 'dictionary entries' => ['<< /A 1 /B 2 >>', new ReaderLimits(maxContainerEntries: 1)];
        yield 'parsed values' => ['[0 1]', new ReaderLimits(maxParsedValues: 2)];
    }

    #[DataProvider('limitedSyntax')]
    public function testLimitsFailBeforeUnboundedParsing(string $source, ReaderLimits $limits): void
    {
        $this->expectException(ParseException::class);
        $this->parse($source, $limits);
    }

    public function testValueBudgetIsSharedBetweenParserInstances(): void
    {
        $limits = new ReaderLimits(maxParsedValues: 2);
        $budget = new ReadBudget($limits);
        (new SyntaxParser(new ByteCursor('true'), $limits, $budget))->read();
        (new SyntaxParser(new ByteCursor('false'), $limits, $budget))->read();
        $this->expectException(ParseException::class);
        (new SyntaxParser(new ByteCursor('null'), $limits, $budget))->read();
    }

    public function testReferenceAndDecodedBudgetsRejectOverflowWithoutArithmeticOverflow(): void
    {
        $limits = new ReaderLimits(maxCrossReferenceEntries: 2, maxTotalDecodedBytes: 3);
        foreach (['crossReferences', 'decoded'] as $method) {
            $budget = new ReadBudget($limits);
            $budget->{$method}(1);
            try {
                $budget->{$method}(PHP_INT_MAX);
                self::fail('A cumulative budget exceeded its ceiling.');
            } catch (ParseException) {
                self::assertTrue(true);
            }
            try {
                $budget->{$method}(-1);
                self::fail('A negative budget debit was accepted.');
            } catch (ParseException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReaderLimitsRejectZeroBeforeReading(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReaderLimits(maxDepth: 0);
    }

    public function testDiagnosticsDoNotExposeSuppliedDocumentBytes(): void
    {
        try {
            $this->parse('private-secret');
            self::fail('An invalid scalar was accepted.');
        } catch (ParseException $error) {
            self::assertSame(0, $error->offset);
            self::assertStringNotContainsString('private-secret', $error->getMessage());
        }
    }

    public function testFileBytesAndEncodedStreamsRetainBytesWithoutIoOrDecoding(): void
    {
        $file = new FileBytes("%PDF-1.7\n\0data");
        self::assertSame("%PDF-1.7\n\0data", $file->bytes);
        $dictionary = new DictionaryType();
        $stream = new EncodedStream($dictionary, "\0\xffendstream");
        self::assertSame($dictionary, $stream->dictionary());
        self::assertSame("\0\xffendstream", $stream->encodedBytes());
        $this->expectException(\LogicException::class);
        $stream->toPdf(new SerializationContext());
    }
}
