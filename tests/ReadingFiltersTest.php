<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\EncodedStream;
use PDF\Reading\Filters\Ascii85Decoder;
use PDF\Reading\Filters\AsciiHexDecoder;
use PDF\Reading\Filters\FlateDecoder;
use PDF\Reading\Filters\PredictorDecoder;
use PDF\Reading\Filters\PredictorParameters;
use PDF\Reading\Filters\RunLengthDecoder;
use PDF\Reading\ObjectReference;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\StreamDecoder;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Filters\ASCII85Decode;
use PDF\Syntax\Filters\ASCIIHexDecode;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\RunLengthDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingFiltersTest extends TestCase
{
    public function testAsciiHexWhitespaceAndFinalOddNibble(): void
    {
        $decoder = new AsciiHexDecoder();
        self::assertSame("\x61\x62\x30", $decoder->decode("6\t1\n62\0\f3>\r ", new ReadBudget(new ReaderLimits())));
        self::assertSame('', $decoder->decode('>', new ReadBudget(new ReaderLimits())));
    }

    public function testAscii85IndependentVectorsAndPartialGroups(): void
    {
        $decoder = new Ascii85Decoder();
        $vectors = ['~>' => '', 'z~>' => "\0\0\0\0", '!!~>' => "\0", '!!!~>' => "\0\0",
            '!!!!~>' => "\0\0\0", '87cURD]j7BEbo80~>' => 'Hello world!', 's8W-!~>' => "\xff\xff\xff\xff"];
        $vectors += ['rr~>' => "\xff", 's8N~>' => "\xff\xff", 's8W*~>' => "\xff\xff\xff"];
        foreach ($vectors as $encoded => $decoded) {
            self::assertSame($decoded, $decoder->decode($encoded, new ReadBudget(new ReaderLimits())));
        }
        self::assertSame('Hello world!', $decoder->decode("87cUR\nD]j7\tBEbo80~>\r\0", new ReadBudget(new ReaderLimits())));
    }

    public function testRunLengthLiteralAndRepeatedPacketBoundaries(): void
    {
        $decoder = new RunLengthDecoder();
        $encoded = "\0a\x02bcd\xffx\x81y\x80";
        self::assertSame('abcdxx' . str_repeat('y', 128), $decoder->decode($encoded, new ReadBudget(new ReaderLimits())));
        self::assertSame('', $decoder->decode("\x80", new ReadBudget(new ReaderLimits())));
    }

    public function testAllSupportedFiltersRoundTripBinaryDataAndEmptyStreams(): void
    {
        $data = implode('', array_map(chr(...), range(0, 255))) . str_repeat("\0abc\xff", 600);
        foreach ([new ASCIIHexDecode(), new ASCII85Decode(), new RunLengthDecode(), new FlateDecode()] as $encoder) {
            foreach (['', $data] as $input) {
                $stream = $this->stream($encoder->name(), $encoder->encode($input));
                self::assertSame($input, (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
            }
        }
    }

    public function testFlateInputChunksCrossCompressionAndChecksumBoundaries(): void
    {
        $input = implode('', array_map(chr(...), range(0, 255))) . str_repeat('abc', 700);
        $encoded = (new FlateDecode(0))->encode($input);
        self::assertGreaterThan(64, strlen($encoded));
        self::assertSame($input, (new FlateDecoder())->decode($encoded, new ReadBudget(new ReaderLimits())));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedData(): iterable
    {
        yield 'hex missing marker' => ['ASCIIHexDecode', '61'];
        yield 'hex invalid byte' => ['ASCIIHexDecode', '6G>'];
        yield 'hex vertical tab is not PDF whitespace' => ['ASCIIHexDecode', "61\x0b>"];
        yield 'hex data follows marker' => ['ASCIIHexDecode', '61>62'];
        yield 'ascii85 missing marker' => ['ASCII85Decode', '!!!!!'];
        yield 'ascii85 one final digit' => ['ASCII85Decode', '!~>'];
        yield 'ascii85 overflow' => ['ASCII85Decode', 'uuuuu~>'];
        yield 'ascii85 zero inside group' => ['ASCII85Decode', '!z~>'];
        yield 'ascii85 PostScript prefix' => ['ASCII85Decode', '<~z~>'];
        yield 'ascii85 invalid byte' => ['ASCII85Decode', 'v~>'];
        yield 'ascii85 incomplete end marker' => ['ASCII85Decode', '!!~'];
        yield 'ascii85 split end marker' => ['ASCII85Decode', "!!~ >"];
        yield 'ascii85 data follows marker' => ['ASCII85Decode', 'z~>z'];
        yield 'runlength missing marker' => ['RunLengthDecode', "\0a"];
        yield 'runlength truncated literal' => ['RunLengthDecode', "\x02ab"];
        yield 'runlength truncated repeat' => ['RunLengthDecode', "\xff"];
        yield 'runlength data follows marker' => ['RunLengthDecode', "\x80\0"];
        yield 'flate invalid framing' => ['FlateDecode', 'not zlib'];
        yield 'flate raw deflate' => ['FlateDecode', "\x03\0"];
        yield 'flate truncated checksum' => ['FlateDecode', substr((new FlateDecode())->encode('hello'), 0, -1)];
        yield 'flate corrupt checksum' => ['FlateDecode', substr((new FlateDecode())->encode('hello'), 0, -1) . "\0"];
        yield 'flate trailing bytes' => ['FlateDecode', (new FlateDecode())->encode('hello') . "\0"];
        yield 'flate concatenated streams' => ['FlateDecode', (new FlateDecode())->encode('hello') . (new FlateDecode())->encode('world')];
    }

    #[DataProvider('malformedData')]
    public function testMalformedEncodedDataFailsExplicitly(string $filter, string $bytes): void
    {
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode($this->stream(new NameType($filter), $bytes), new ReadBudget(new ReaderLimits()));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function expandedLimits(): iterable
    {
        yield 'raw' => ['', 'abc', 2];
        yield 'hex' => ['ASCIIHexDecode', '616263>', 2];
        yield 'ascii85' => ['ASCII85Decode', 'z~>', 3];
        yield 'runlength' => ['RunLengthDecode', "\x81a\x80", 127];
        yield 'flate bomb' => ['FlateDecode', (new FlateDecode())->encode(str_repeat('a', 1048576)), 128];
    }

    #[DataProvider('expandedLimits')]
    public function testEveryStageEnforcesItsOutputLimit(string $filter, string $bytes, int $maximum): void
    {
        $limits = new ReaderLimits(maxDecodedStreamBytes: $maximum);
        $this->expectException(ParseException::class);
        $stream = new EncodedStream($filter === '' ? new DictionaryType() : $this->filterDictionary(new NameType($filter)), $bytes);
        (new StreamDecoder($limits))->decode($stream, new ReadBudget($limits));
    }

    public function testDecodingChainsFollowDeclaredOrderAndLeaveEncodedBytesIntact(): void
    {
        $input = str_repeat('chain', 80);
        $compressed = (new FlateDecode())->encode($input);
        $encoded = (new ASCII85Decode())->encode($compressed);
        $filter = new ArrayType(new NameType('ASCII85Decode'), new NameType('FlateDecode'));
        $stream = $this->stream($filter, $encoded, new ArrayType(new NullType(), new NullType()));
        self::assertSame($input, (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
        self::assertSame($encoded, $stream->encodedBytes());
    }

    public function testIntermediateChainOutputCountsTowardsCumulativeBudget(): void
    {
        $input = str_repeat('chain', 80);
        $compressed = (new FlateDecode())->encode($input);
        $stream = $this->stream(
            new ArrayType(new NameType('ASCIIHexDecode'), new NameType('FlateDecode')),
            (new ASCIIHexDecode())->encode($compressed)
        );
        $exact = new ReaderLimits(maxTotalDecodedBytes: strlen($compressed) + strlen($input));
        self::assertSame($input, (new StreamDecoder($exact))->decode($stream, new ReadBudget($exact)));
        $tooSmall = new ReaderLimits(maxTotalDecodedBytes: strlen($compressed) + strlen($input) - 1);
        $this->expectException(ParseException::class);
        (new StreamDecoder($tooSmall))->decode($stream, new ReadBudget($tooSmall));
    }

    public function testRawStreamsShareTheDocumentCumulativeBudget(): void
    {
        $limits = new ReaderLimits(maxTotalDecodedBytes: 5);
        $budget = new ReadBudget($limits);
        $decoder = new StreamDecoder($limits);
        self::assertSame('abc', $decoder->decode(new EncodedStream(new DictionaryType(), 'abc'), $budget));
        $this->expectException(ParseException::class);
        $decoder->decode(new EncodedStream(new DictionaryType(), 'def'), $budget);
    }

    public function testTiffPredictorAddsEachColorWithinItsOwnRow(): void
    {
        $parameters = new PredictorParameters(2, 3, 8, 2);
        $input = "\x0a\x14\x1e\x1e\x1e\x1e\x0b\x15\x1f\x1e\x1e\x1e";
        self::assertSame(
            "\x0a\x14\x1e\x28\x32\x3c\x0b\x15\x1f\x29\x33\x3d",
            (new PredictorDecoder($parameters))->decode($input, new ReadBudget(new ReaderLimits()))
        );
    }

    public function testPngPredictorUsesEachRowTagEvenWhenDictionaryNamesAFixedPredictor(): void
    {
        $parameters = new PredictorParameters(12, 3, 8, 2);
        $input = "\x00\x0a\x14\x1e\x28\x32\x3c"
            . "\x01\x0b\x15\x1f\x1e\x1e\x1e"
            . "\x02\x01\x01\x01\x01\x01\x01"
            . "\x03\x08\x0d\x12\x10\x10\x10"
            . "\x04\x01\x01\x01\x01\x01\x01";
        self::assertSame(
            "\x0a\x14\x1e\x28\x32\x3c"
            . "\x0b\x15\x1f\x29\x33\x3d"
            . "\x0c\x16\x20\x2a\x34\x3e"
            . "\x0e\x18\x22\x2c\x36\x40"
            . "\x0f\x19\x23\x2d\x37\x41",
            (new PredictorDecoder($parameters))->decode($input, new ReadBudget(new ReaderLimits()))
        );
    }

    public function testPredictorFromStreamDictionaryProducesCrossReferenceRowBytes(): void
    {
        $parameters = new DictionaryType(
            new Entry(new NameType('Predictor'), new IntegerType(12)),
            new Entry(new NameType('Columns'), new IntegerType(3))
        );
        $input = "\x00\x01\x02\x03\x02\x01\x01\x01";
        $stream = $this->stream(new NameType('FlateDecode'), (new FlateDecode())->encode($input), $parameters);
        self::assertSame("\x01\x02\x03\x02\x03\x04", (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
    }

    public function testPredictorIntermediateAndFinalBytesAreAccountedExactlyOnce(): void
    {
        $parameters = new DictionaryType(
            new Entry(new NameType('Predictor'), new IntegerType(10)),
            new Entry(new NameType('Columns'), new IntegerType(3))
        );
        $stream = $this->stream(new NameType('FlateDecode'), (new FlateDecode())->encode("\0abc"), $parameters);
        $exact = new ReaderLimits(maxTotalDecodedBytes: 7);
        self::assertSame('abc', (new StreamDecoder($exact))->decode($stream, new ReadBudget($exact)));
        $tooSmall = new ReaderLimits(maxTotalDecodedBytes: 6);
        $this->expectException(ParseException::class);
        (new StreamDecoder($tooSmall))->decode($stream, new ReadBudget($tooSmall));
    }

    /** @return iterable<string, array{PredictorParameters, string}> */
    public static function malformedPredictors(): iterable
    {
        yield 'TIFF partial row' => [new PredictorParameters(2, 1, 8, 3), 'ab'];
        yield 'PNG partial row' => [new PredictorParameters(12, 1, 8, 3), "\0ab"];
        yield 'PNG invalid row tag' => [new PredictorParameters(12), "\x05a"];
        yield 'geometry overflows' => [new PredictorParameters(12, 2, 8, PHP_INT_MAX), ''];
        yield 'row exceeds output limit' => [new PredictorParameters(12, 1, 8, 67108865), ''];
    }

    #[DataProvider('malformedPredictors')]
    public function testPredictorFramingAndAllocationAreValidated(PredictorParameters $parameters, string $bytes): void
    {
        $this->expectException(ParseException::class);
        (new PredictorDecoder($parameters))->decode($bytes, new ReadBudget(new ReaderLimits()));
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidPredictorParameters(): iterable
    {
        yield 'unknown predictor' => [3, 1, 8, 1];
        yield 'zero colors' => [12, 0, 8, 1];
        yield 'negative columns' => [12, 1, 8, -1];
        yield 'invalid bit depth' => [12, 1, 7, 1];
    }

    #[DataProvider('invalidPredictorParameters')]
    public function testPredictorParametersRejectInvalidOrUnsupportedValues(int $predictor, int $colors, int $bits, int $columns): void
    {
        $this->expectException(ParseException::class);
        new PredictorParameters($predictor, $colors, $bits, $columns);
    }

    /** @return iterable<string, array{DictionaryType}> */
    public static function invalidStreamDictionaries(): iterable
    {
        yield 'filter has wrong type' => [new DictionaryType(new Entry(new NameType('Filter'), new IntegerType(1)))];
        yield 'filter element has wrong type' => [new DictionaryType(new Entry(new NameType('Filter'), new ArrayType(new NullType())))];
        yield 'orphan parameters' => [new DictionaryType(new Entry(new NameType('DecodeParms'), new DictionaryType()))];
        yield 'single filter array parameters' => [new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('FlateDecode')),
            new Entry(new NameType('DecodeParms'), new ArrayType())
        )];
        yield 'array filter dictionary parameters' => [new DictionaryType(
            new Entry(new NameType('Filter'), new ArrayType(new NameType('FlateDecode'))),
            new Entry(new NameType('DecodeParms'), new DictionaryType())
        )];
        yield 'different array lengths' => [new DictionaryType(
            new Entry(new NameType('Filter'), new ArrayType(new NameType('FlateDecode'))),
            new Entry(new NameType('DecodeParms'), new ArrayType())
        )];
        yield 'invalid parameter element' => [new DictionaryType(
            new Entry(new NameType('Filter'), new ArrayType(new NameType('FlateDecode'))),
            new Entry(new NameType('DecodeParms'), new ArrayType(new IntegerType(1)))
        )];
    }

    #[DataProvider('invalidStreamDictionaries')]
    public function testDecodeParametersMustMatchFilterShape(DictionaryType $dictionary): void
    {
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode(new EncodedStream($dictionary, ''), new ReadBudget(new ReaderLimits()));
    }

    public function testTooManyFiltersAreRejectedBeforeDecoding(): void
    {
        $limits = new ReaderLimits(maxFilters: 1);
        $this->expectException(ParseException::class);
        (new StreamDecoder($limits))->decode(
            $this->stream(new ArrayType(new NameType('ASCIIHexDecode'), new NameType('FlateDecode')), '>'),
            new ReadBudget($limits)
        );
    }

    public function testUnsupportedFilterStillAllowsRawInspection(): void
    {
        $stream = $this->stream(new NameType('DCTDecode'), 'jpeg bytes');
        self::assertSame('jpeg bytes', $stream->encodedBytes());
        $this->expectException(UnsupportedPdfFeature::class);
        (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits()));
    }

    public function testInlineAbbreviationsAreRejectedInAnOrdinaryStream(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        (new StreamDecoder())->decode($this->stream(new NameType('Fl'), (new FlateDecode())->encode('hello')), new ReadBudget(new ReaderLimits()));
    }

    public function testExternalStreamsAreNeverFetched(): void
    {
        $dictionary = new DictionaryType(new Entry(new NameType('F'), new NameType('https://invalid.example/data')));
        $this->expectException(UnsupportedPdfFeature::class);
        (new StreamDecoder())->decode(new EncodedStream($dictionary, 'local bytes'), new ReadBudget(new ReaderLimits()));
    }

    public function testAnExternalFileReferenceResolvingToNullIsEquivalentToAbsence(): void
    {
        $resolver = new class implements ObjectResolver {
            public function resolve(BasicType $value): BasicType
            {
                return $value instanceof ObjectReference ? new NullType() : $value;
            }
        };
        $dictionary = new DictionaryType(new Entry(new NameType('F'), new ObjectReference(new ObjectIdentifier(new ObjectNumber(99)))));
        self::assertSame('local bytes', (new StreamDecoder())->decode(new EncodedStream($dictionary, 'local bytes'),
            new ReadBudget(new ReaderLimits()), $resolver));
    }

    public function testIndirectFilterArraysAndPredictorEntriesUseTheExplicitResolver(): void
    {
        $references = [];
        foreach (range(1, 4) as $number) {
            $references[$number] = new ObjectReference(new ObjectIdentifier(new ObjectNumber($number)));
        }
        $parameters = new DictionaryType(
            new Entry(new NameType('Predictor'), $references[4]),
            new Entry(new NameType('Columns'), new IntegerType(3))
        );
        $resolved = [1 => new ArrayType($references[2]), 2 => new NameType('FlateDecode'),
            3 => new ArrayType($parameters), 4 => new IntegerType(12)];
        $resolver = new class ($resolved) implements ObjectResolver {
            /** @param array<int, BasicType> $values */
            public function __construct(private readonly array $values)
            {
            }
            public function resolve(BasicType $value): BasicType
            {
                return $value instanceof ObjectReference ? $this->values[$value->identifier->objectNumber->value] : $value;
            }
        };
        $stream = $this->stream($references[1], (new FlateDecode())->encode("\0abc"), $references[3]);
        self::assertSame('abc', (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits()), $resolver));
        $this->expectException(UnsupportedPdfFeature::class);
        (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits()));
    }

    public function testPredictionDisabledDoesNotReadIrrelevantParameters(): void
    {
        $parameters = new DictionaryType(new Entry(new NameType('Colors'), new NameType('irrelevant')));
        self::assertSame('abc', (new StreamDecoder())->decode(
            $this->stream(new NameType('FlateDecode'), (new FlateDecode())->encode('abc'), $parameters),
            new ReadBudget(new ReaderLimits()),
        ));
    }

    public function testMissingNullAndEmptyFilterEntriesAllDescribeRawBytes(): void
    {
        foreach ([new DictionaryType(), $this->filterDictionary(new NullType(), new NullType()),
            $this->filterDictionary(new ArrayType(), new ArrayType())] as $dictionary) {
            self::assertSame('raw', (new StreamDecoder())->decode(new EncodedStream($dictionary, 'raw'), new ReadBudget(new ReaderLimits())));
        }
    }

    public function testNonIntegerPredictorParameterIsRejected(): void
    {
        $parameters = new DictionaryType(new Entry(new NameType('Predictor'), new NameType('12')));
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode(
            $this->stream(new NameType('FlateDecode'), (new FlateDecode())->encode('abc'), $parameters),
            new ReadBudget(new ReaderLimits())
        );
    }

    private function filterDictionary(BasicType $filter, ?BasicType $parameters = null): DictionaryType
    {
        $entries = [new Entry(new NameType('Filter'), $filter)];
        if ($parameters !== null) {
            $entries[] = new Entry(new NameType('DecodeParms'), $parameters);
        }
        return new DictionaryType(...$entries);
    }

    private function stream(BasicType $filter, string $bytes, ?BasicType $parameters = null): EncodedStream
    {
        return new EncodedStream($this->filterDictionary($filter, $parameters), $bytes);
    }
}
