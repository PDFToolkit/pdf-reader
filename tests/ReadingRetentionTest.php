<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\IndirectObjectReader;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\StreamDecoder;
use PDF\Reading\SyntaxParser;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\TestCase;

final class ReadingRetentionTest extends TestCase
{
    public function testNamesLiteralAndHexStringsShareTheRetentionBudget(): void
    {
        $limits = new ReaderLimits(maxRetainedBytes: 6);
        $budget = new ReadBudget($limits);
        $first = new SyntaxParser(new ByteCursor('[/A (BC) <4445>]'), $limits, $budget);
        self::assertInstanceOf(ArrayType::class, $first->read());
        $this->expectException(ParseException::class);
        (new SyntaxParser(new ByteCursor('(FG)'), $limits, $budget))->read();
    }

    public function testEncodedStreamsCheckRetentionBeforeExtractingTheirPayload(): void
    {
        $limits = new ReaderLimits(maxRetainedBytes: 13);
        $source = new FileBytes("1 0 obj << /Length 8 >>\nstream\n12345678\nendstream\nendobj");
        $reader = new IndirectObjectReader($source, $limits, new ReadBudget($limits));
        $this->expectException(ParseException::class);
        $reader->read(0);
    }

    public function testRepeatedSourceViewsCannotRetainUnboundedCopies(): void
    {
        $limits = new ReaderLimits(maxRetainedBytes: 25);
        $source = new FileBytes("1 0 obj << /Length 3 >>\nstream\nabc\nendstream\nendobj");
        $reader = new IndirectObjectReader($source, $limits, new ReadBudget($limits));
        self::assertInstanceOf(EncodedStream::class, $reader->read(0)->value);
        self::assertInstanceOf(EncodedStream::class, $reader->read(0)->value);
        $this->expectException(ParseException::class);
        $reader->read(0);
    }

    public function testDecodedStreamCacheDoesNotSpendItsBudgetTwice(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf/classic.pdf');
        self::assertNotFalse($bytes);
        $document = (new DocumentReader(new ReaderLimits(maxTotalDecodedBytes: 297)))->read(new FileBytes($bytes));
        $reference = $document->catalog()->get('FixtureData');
        self::assertNotNull($reference);
        $stream = $document->resolve($reference);
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame(297, strlen($document->streamData($stream)));
        self::assertSame(297, strlen($document->streamData($stream)));
    }

    public function testUnsupportedFilterDiagnosticsDoNotEchoSourceNames(): void
    {
        $stream = new EncodedStream(new DictionaryType(new Entry(new NameType('Filter'), new NameType('private-source-data'))), '');
        try {
            (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits()));
            self::fail('An unsupported filter must be rejected.');
        } catch (UnsupportedPdfFeature $exception) {
            self::assertStringNotContainsString('private-source-data', $exception->getMessage());
        }
    }
}
