<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\PdfMacCoverage;
use PDF\Reading\SourceRevision;
use PDF\Reading\SourceStringSpans;
use PDF\Reading\SyntaxParser;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingSourceProvenanceTest extends TestCase
{
    public function testEqualBytesDoNotConflateTwoDifferentSourceTokens(): void
    {
        $budget = new ReadBudget(new ReaderLimits());
        $spans = new SourceStringSpans($budget);
        $source = '<< /MAC <1122> /Contents <1122> >>';
        $dictionary = (new SyntaxParser(new ByteCursor($source), budget: $budget, sourceStrings: $spans))->read();
        self::assertInstanceOf(DictionaryType::class, $dictionary);
        $mac = $dictionary->get('MAC');
        $contents = $dictionary->get('Contents');
        self::assertInstanceOf(HexadecimalString::class, $mac);
        self::assertInstanceOf(HexadecimalString::class, $contents);
        self::assertNotSame($mac, $contents);
        self::assertSame(8, $spans->of($mac)?->start);
        self::assertSame(14, $spans->of($mac)?->end);
        self::assertSame(25, $spans->of($contents)?->start);
        self::assertNull($spans->of(new HexadecimalString($mac->value)));
    }

    public function testLocationMetadataUsesTheSameCumulativeRetentionLimit(): void
    {
        $spans = new SourceStringSpans(new ReadBudget(new ReaderLimits(maxRetainedBytes: 63)));
        $this->expectException(ParseException::class);
        $spans->record(new HexadecimalString(''), 0, 2);
    }

    public function testOnlyTheActualPayloadExtentCanBeExcluded(): void
    {
        [$source, $dictionary, $spans] = $this->coverageSource();
        $value = $dictionary->get('MAC');
        self::assertInstanceOf(HexadecimalString::class, $value);
        $span = $spans->of($value);
        self::assertNotNull($span);
        $covered = (new PdfMacCoverage(new SourceRevision(new FileBytes($source), $dictionary, $spans, new ReadBudget(new ReaderLimits()))))->coveredBytes($dictionary, 'MAC', true);
        self::assertSame(substr($source, 0, $span->start) . substr($source, $span->end), $covered);
    }

    public function testCoveredCiphertextIsChargedBeforeItsBufferIsRetained(): void
    {
        [$source, $dictionary, $spans] = $this->coverageSource();
        $revision = new SourceRevision(new FileBytes($source), $dictionary, $spans, new ReadBudget(new ReaderLimits(maxRetainedBytes: 1)));
        $this->expectException(ParseException::class);
        (new PdfMacCoverage($revision))->coveredBytes($dictionary, 'MAC', true);
    }

    /** @return iterable<string, array{int, int}> */
    public static function falseExtents(): iterable
    {
        yield 'exclude an additional leading byte' => [-1, 0];
        yield 'exclude an additional trailing byte' => [0, 1];
        yield 'leave a payload delimiter covered' => [1, 0];
        yield 'point to an equal-length unrelated token' => [-7, -7];
    }

    #[DataProvider('falseExtents')]
    public function testAnAuthenticatedRangeCannotClaimAnotherLexicalExtent(int $startChange, int $endChange): void
    {
        [$source, $dictionary, $spans] = $this->coverageSource($startChange, $endChange);
        $this->expectException(ParseException::class);
        (new PdfMacCoverage(new SourceRevision(new FileBytes($source), $dictionary, $spans, new ReadBudget(new ReaderLimits()))))->coveredBytes($dictionary, 'MAC', true);
    }

    public function testAParsedDecodedSliceCannotClaimSourceFileProvenance(): void
    {
        [$source] = $this->coverageSource();
        $dictionary = (new SyntaxParser(new ByteCursor($source, 8)))->read();
        self::assertInstanceOf(DictionaryType::class, $dictionary);
        $spans = new SourceStringSpans(new ReadBudget(new ReaderLimits()));
        $this->expectException(ParseException::class);
        (new PdfMacCoverage(new SourceRevision(new FileBytes($source), $dictionary, $spans, new ReadBudget(new ReaderLimits()))))->coveredBytes($dictionary, 'MAC', true);
    }

    /** @return array{string, DictionaryType, SourceStringSpans} */
    private function coverageSource(int $startChange = 0, int $endChange = 0): array
    {
        $source = "%prefix\n<< /MAC <1122> /ByteRange [0000000000 0000000000 0000000000 0000000000] >>\n";
        $first = strpos($source, '<1122>');
        self::assertNotFalse($first);
        $second = $first + 6 + $endChange;
        $source = str_replace('[0000000000 0000000000 0000000000 0000000000]', sprintf('[0000000000 %010d %010d %010d]', $first + $startChange, $second, strlen($source) - $second), $source);
        $budget = new ReadBudget(new ReaderLimits());
        $spans = new SourceStringSpans($budget);
        $dictionary = (new SyntaxParser(new ByteCursor($source, 8), budget: $budget, sourceStrings: $spans))->read();
        self::assertInstanceOf(DictionaryType::class, $dictionary);
        return [$source, $dictionary, $spans];
    }
}
