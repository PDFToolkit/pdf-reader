<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\EncodedStream;
use PDF\Reading\Filters\CcittFaxDecoder;
use PDF\Reading\Filters\CcittFaxLimits;
use PDF\Reading\Filters\CcittFaxParameters;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\StreamDecoder;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingCcittFaxTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function independentCases(): iterable
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/fax/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('independentCases')]
    public function testIndependentLibtiffPixels(array $case): void
    {
        $bytes = base64_decode($case['encoded'], true);
        self::assertIsString($bytes);
        self::assertSame($case['encodedSha256'], hash('sha256', $bytes));
        $actual = (new StreamDecoder())->decode($this->stream($bytes, $case['params']), new ReadBudget(new ReaderLimits()));
        self::assertSame(base64_decode($case['bitmap'], true), $actual);
    }

    public function testPolarityAndNonByteAlignedPadding(): void
    {
        // A nine-pixel white scanline, then nine black pixels (T.4 terminating codes).
        $bits = '10100' . '00110101' . '000100';
        foreach ([false => "\xff\x80\0\0", true => "\0\0\xff\x80"] as $black => $expected) {
            self::assertSame($expected, $this->decode($bits, new CcittFaxParameters(columns: 9, rows: 2, endOfBlock: false, blackIs1: (bool) $black)));
        }
    }

    public function testDamagedRowsUseHealthyPreviousRowThenWhite(): void
    {
        $eol = '000000000001';
        $bits = $eol . '00110101000101' . $eol . '11111111111111' . $eol . '11111111111111' . $eol . '10011';
        self::assertSame("\0\0\xff\xff", $this->decode($bits, new CcittFaxParameters(columns: 8, rows: 4, endOfLine: true, endOfBlock: false, damagedRowsBeforeError: 2)));
        $this->expectException(ParseException::class);
        $this->decode($bits, new CcittFaxParameters(columns: 8, rows: 4, endOfLine: true, endOfBlock: false, damagedRowsBeforeError: 1));
    }

    public function testFirstDamagedRowBecomesWhiteAndRecoveryDoesNotHideWorkLimit(): void
    {
        $bits = '0000000000011111111111111100000000000110011';
        $parameters = new CcittFaxParameters(columns: 8, rows: 2, endOfLine: true, endOfBlock: false, damagedRowsBeforeError: 1);
        self::assertSame("\xff\xff", $this->decode($bits, $parameters));
        $this->expectExceptionMessage('bit-work budget');
        $this->decode($bits, $parameters, work: new CcittFaxLimits(maxBitReads: 20));
    }

    public function testRecoverySearchHasItsOwnBound(): void
    {
        $bits = '0000000000011111111111111100000000000110011';
        $this->expectExceptionMessage('bounded search');
        $this->decode($bits, new CcittFaxParameters(columns: 8, rows: 2, endOfLine: true, endOfBlock: false, damagedRowsBeforeError: 1), work: new CcittFaxLimits(maxRecoveryBits: 3));
    }

    public function testDamagePermissionDoesNotApplyWithoutRequiredEol(): void
    {
        $this->expectException(ParseException::class);
        $this->decode('11111111111111', new CcittFaxParameters(columns: 8, rows: 1, endOfBlock: false, damagedRowsBeforeError: 100));
    }

    public function testEndOfDataMayPrecedeRowsAndUnknownRowsAreAccepted(): void
    {
        foreach ([0, 3] as $rows) {
            self::assertSame("\xff", $this->decode('10011', new CcittFaxParameters(columns: 8, rows: $rows, endOfBlock: false)));
        }
    }

    public function testPositiveKDoesNotEnforceAnEncoderCadence(): void
    {
        // One white MH line followed by two all-white vertical 2-D lines, K=1.
        self::assertSame("\xff\xff\xff", $this->decode('1100110101', new CcittFaxParameters(k: 1, columns: 8, rows: 3, endOfBlock: false)));
    }

    /** @return iterable<string, array{string, CcittFaxParameters}> */
    public static function malformedRows(): iterable
    {
        yield 'truncated makeup' => ['11011', new CcittFaxParameters(columns: 64, rows: 1, endOfBlock: false)];
        yield 'overflowing run' => ['10100', new CcittFaxParameters(columns: 8, rows: 1, endOfBlock: false)];
        yield 'missing EOL' => ['10011', new CcittFaxParameters(columns: 8, rows: 1, endOfLine: true, endOfBlock: false)];
        yield 'missing RTC' => ['10011', new CcittFaxParameters(columns: 8)];
        yield 'missing EOFB' => ['1', new CcittFaxParameters(k: -1, columns: 8)];
        yield 'invalid 2-D mode' => ['0000000', new CcittFaxParameters(k: -1, columns: 8)];
        yield 'mixed starts 2-D' => ['01', new CcittFaxParameters(k: 2, columns: 8, rows: 1, endOfBlock: false)];
        yield 'invalid RTC' => [str_repeat('000000000001', 5), new CcittFaxParameters(columns: 8)];
        yield 'nonzero byte alignment' => ['10011111' . '10011', new CcittFaxParameters(columns: 8, rows: 2, encodedByteAlign: true, endOfBlock: false)];
    }

    #[DataProvider('malformedRows')]
    public function testMalformedRowsFailExplicitly(string $bits, CcittFaxParameters $parameters): void
    {
        $this->expectException(ParseException::class);
        $this->decode($bits, $parameters);
    }

    public function testOptionalExtensionIsNotTreatedAsDamage(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        $this->decode('0000001000', new CcittFaxParameters(k: -1, columns: 8, damagedRowsBeforeError: 10));
    }

    public function testEofbPaddingMustBeZeroIncludingItsPartialByte(): void
    {
        $prefix = '1' . '000000000001000000000001';
        self::assertSame("\xff", $this->decode($prefix . str_repeat('0', 23), new CcittFaxParameters(k: -1, columns: 8)));
        $this->expectExceptionMessage('zero padding');
        $this->decode($prefix . '1111111', new CcittFaxParameters(k: -1, columns: 8));
    }

    public function testUncompressedMissingExitIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->decode('0000001111' . '11111111' . '000000000001000000000001', new CcittFaxParameters(k: -1, columns: 8));
    }

    /** @return iterable<string, array{ReaderLimits, CcittFaxLimits}> */
    public static function budgets(): iterable
    {
        yield 'single output' => [new ReaderLimits(maxDecodedStreamBytes: 1), new CcittFaxLimits()];
        yield 'cumulative output' => [new ReaderLimits(maxTotalDecodedBytes: 1), new CcittFaxLimits()];
        yield 'retained transitions' => [new ReaderLimits(maxRetainedBytes: 1), new CcittFaxLimits()];
        yield 'row count' => [new ReaderLimits(), new CcittFaxLimits(maxRows: 1)];
        yield 'bit work' => [new ReaderLimits(), new CcittFaxLimits(maxBitReads: 1)];
    }

    #[DataProvider('budgets')]
    public function testResourceCeilings(ReaderLimits $limits, CcittFaxLimits $work): void
    {
        $this->expectException(ParseException::class);
        $this->decode('1001110011', new CcittFaxParameters(columns: 8, rows: 2, endOfBlock: false), $limits, $work);
    }

    public function testTransitionLimitIsCheckedBeforeAllocation(): void
    {
        $this->expectExceptionMessage('transition budget');
        $this->decode('00110101000101', new CcittFaxParameters(columns: 8, rows: 1, endOfBlock: false), work: new CcittFaxLimits(maxRowTransitions: 1));
    }

    public function testCumulativeBudgetIsSharedAcrossStreams(): void
    {
        $limits = new ReaderLimits(maxTotalDecodedBytes: 1);
        $budget = new ReadBudget($limits);
        $decoder = new CcittFaxDecoder($limits, new CcittFaxParameters(columns: 8, rows: 1, endOfBlock: false));
        self::assertSame("\xff", $decoder->decode($this->pack('10011'), $budget));
        $this->expectExceptionMessage('cumulative decoded-byte');
        $decoder->decode($this->pack('10011'), $budget);
    }

    /** @return iterable<string, array{array<string, bool|int>}> */
    public static function invalidParameters(): iterable
    {
        yield 'zero width' => [['Columns' => 0]];
        yield 'overflow width' => [['Columns' => PHP_INT_MAX]];
        yield 'negative rows' => [['Rows' => -1]];
        yield 'negative damage' => [['DamagedRowsBeforeError' => -1]];
        yield 'nonboolean EOL' => [['EndOfLine' => 1]];
        yield 'noninteger K' => [['K' => true]];
    }

    /** @param array<string, bool|int> $parameters */
    #[DataProvider('invalidParameters')]
    public function testInvalidParameters(array $parameters): void
    {
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode($this->stream('', $parameters), new ReadBudget(new ReaderLimits()));
    }

    private function decode(string $bits, CcittFaxParameters $parameters, ReaderLimits $limits = new ReaderLimits(), CcittFaxLimits $work = new CcittFaxLimits()): string
    {
        return (new CcittFaxDecoder($limits, $parameters, $work))->decode($this->pack($bits), new ReadBudget($limits));
    }

    private function pack(string $bits): string
    {
        $output = '';
        foreach (str_split(str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '0'), 8) as $byte) {
            $output .= chr(bindec($byte));
        }
        return $output;
    }

    /** @param array<string, bool|int> $parameters */
    private function stream(string $bytes, array $parameters): EncodedStream
    {
        $entries = [];
        foreach ($parameters as $name => $value) {
            $entries[] = new Entry(new NameType($name), is_bool($value) ? new BooleanType($value) : new IntegerType($value));
        }
        return new EncodedStream(new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('CCITTFaxDecode')),
            new Entry(new NameType('DecodeParms'), new DictionaryType(...$entries)),
        ), $bytes);
    }
}
