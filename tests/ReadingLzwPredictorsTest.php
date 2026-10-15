<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\EncodedStream;
use PDF\Reading\Filters\LzwDecoder;
use PDF\Reading\Filters\PredictorDecoder;
use PDF\Reading\Filters\PredictorParameters;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\StreamDecoder;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Filters\FixedPngFilterSelection;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\LZWDecode;
use PDF\Syntax\Filters\PngPredictor;
use PDF\Syntax\Filters\PngRowFilter;
use PDF\Syntax\Filters\PredictorBitsPerComponent;
use PDF\Syntax\Filters\PredictorLayout;
use PDF\Syntax\Filters\TiffPredictor;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingLzwPredictorsTest extends TestCase
{
    public function testIndependentNineBitVectorsAndClearReset(): void
    {
        // Hand-packed 9-bit codes: CLEAR, A, next-code (AA), EOD.
        self::assertSame('AAA', (new LzwDecoder())->decode("\x80\x10\x60\x50\x10", new ReadBudget(new ReaderLimits())));
        // CLEAR, A, B, CLEAR, C, EOD.
        self::assertSame('ABC', (new LzwDecoder())->decode("\x80\x10\x48\x50\x02\x1c\x04", new ReadBudget(new ReaderLimits())));
        self::assertSame('', (new LzwDecoder())->decode("\x80\x40\x40", new ReadBudget(new ReaderLimits())));
    }

    public function testBothEarlyChangePoliciesCrossEveryCodeWidthAndDictionaryReset(): void
    {
        $data = '';
        for ($index = 0; $index < 1600; ++$index) {
            $data .= hash('sha256', pack('N', $index), true);
        }
        foreach ([0, 1] as $earlyChange) {
            $encoder = new LZWDecode(new EarlyChange(new IntegerType($earlyChange)));
            foreach (['', 'A', str_repeat('ABABA', 4000), $data] as $input) {
                $stream = new EncodedStream(new DictionaryType(
                    new Entry(new NameType('Filter'), $encoder->name()),
                    new Entry(new NameType('DecodeParms'), $encoder->parameters()),
                ), $encoder->encode($input));
                self::assertSame($input, (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCodes(): iterable
    {
        yield 'empty missing EOD' => [''];
        yield 'partial code' => ["\x80"];
        yield 'dictionary code before literal' => ["\x80\x40\x80"];
        yield 'undefined dictionary entry' => ["\x80\x10\x7f\xf0"];
    }

    #[DataProvider('invalidCodes')]
    public function testMalformedLzwFailsExplicitly(string $bytes): void
    {
        $this->expectException(ParseException::class);
        (new LzwDecoder())->decode($bytes, new ReadBudget(new ReaderLimits()));
    }

    public function testLzwOutputAndCumulativeLimitsApplyBeforeRetainingExpansion(): void
    {
        $bytes = (new LZWDecode())->encode(str_repeat('A', 10000));
        $limits = new ReaderLimits(maxDecodedStreamBytes: 9999);
        $this->expectException(ParseException::class);
        (new LzwDecoder($limits))->decode($bytes, new ReadBudget($limits));
    }

    public function testLzwEarlyChangeRequiresZeroOrOne(): void
    {
        $dictionary = new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('LZWDecode')),
            new Entry(new NameType('DecodeParms'), new DictionaryType(new Entry(new NameType('EarlyChange'), new IntegerType(2)))),
        );
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode(new EncodedStream($dictionary, ''), new ReadBudget(new ReaderLimits()));
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function independentTiffRows(): iterable
    {
        yield '1-bit row padding' => [1, "\xf5", "\xa5"];
        yield '2-bit wrapping and padding' => [2, "\x51\xf5", "\x6b\xb5"];
        yield '4-bit wrapping and padding' => [4, "\xf2\xe5", "\xf1\xf5"];
        yield '8-bit modulo arithmetic' => [8, "\xff\x02\xfe", "\xff\x01\xff"];
        yield '16-bit carry and modulo arithmetic' => [16, "\xff\xff\x00\x02\xff\xfe", "\xff\xff\x00\x01\xff\xff"];
    }

    #[DataProvider('independentTiffRows')]
    public function testSampleWiseTiffArithmeticAndUnusedPadding(int $bits, string $encoded, string $decoded): void
    {
        $columns = match ($bits) {
            1 => 4, 2 => 5, default => 3
        };
        self::assertSame($decoded, (new PredictorDecoder(new PredictorParameters(2, 1, $bits, $columns)))
            ->decode($encoded, new ReadBudget(new ReaderLimits())));
    }

    public function testEveryDepthAndPngFilterWorksWithFlateAndLzw(): void
    {
        foreach (PredictorBitsPerComponent::cases() as $depth) {
            foreach ([1, 3, 4] as $colors) {
                $layout = new PredictorLayout(new IntegerType($colors), new IntegerType(7), $depth);
                $input = substr(str_repeat("\x05\xff\xa1\x92\x00\x13", $layout->bytesPerRow), 0, 3 * $layout->bytesPerRow);
                $predictors = [new TiffPredictor($layout)];
                foreach (PngRowFilter::cases() as $filter) {
                    $predictors[] = new PngPredictor($layout, new FixedPngFilterSelection($filter));
                }
                foreach ($predictors as $predictor) {
                    foreach ([new FlateDecode(predictor: $predictor), new LZWDecode(predictor: $predictor)] as $encoder) {
                        $dictionary = new DictionaryType(
                            new Entry(new NameType('Filter'), $encoder->name()),
                            new Entry(new NameType('DecodeParms'), $encoder->parameters())
                        );
                        self::assertSame($input, (new StreamDecoder())->decode(
                            new EncodedStream($dictionary, $encoder->encode($input)),
                            new ReadBudget(new ReaderLimits()),
                        ));
                    }
                }
            }
        }
    }
}
