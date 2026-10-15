<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReaderLimits;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingHybridReferencesTest extends TestCase
{
    public function testAuxiliaryCompressedEntryOverridesTheClassicEntryAndIgnoresItsPrev(): void
    {
        $document = (new DocumentReader())->read(new FileBytes($this->hybrid()));
        $value = $document->object(new ObjectIdentifier(new ObjectNumber(4)));
        self::assertInstanceOf(StringType::class, $value);
        self::assertSame('compressed wins', $value->value);
    }

    public function testAuxiliaryFreeEntryOverridesAnOtherwiseLiveClassicEntry(): void
    {
        $document = (new DocumentReader())->read(new FileBytes($this->hybrid(type: 0)));
        self::assertInstanceOf(NullType::class, $document->object(new ObjectIdentifier(new ObjectNumber(4))));
    }

    public function testUnknownAuxiliaryEntryOverridesAnOtherwiseLiveClassicEntry(): void
    {
        $document = (new DocumentReader())->read(new FileBytes($this->hybrid(type: 255)));
        self::assertInstanceOf(NullType::class, $document->object(new ObjectIdentifier(new ObjectNumber(4))));
    }

    public function testNewerClassicRevisionOverridesAnOlderHybridCompressedEntry(): void
    {
        $original = $this->hybrid();
        preg_match('/startxref\n([0-9]+)\n%%EOF/', $original, $matches);
        $objectOffset = strlen($original);
        $object = "4 0 obj\n(newer revision)\nendobj\n";
        $xrefOffset = $objectOffset + strlen($object);
        $bytes = $original . $object . "xref\n4 1\n" . sprintf("%010d 00000 n \n", $objectOffset)
            . 'trailer << /Size 6 /Root 1 0 R /Prev ' . $matches[1] . ">>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
        $value = (new DocumentReader())->read(new FileBytes($bytes))->object(new ObjectIdentifier(new ObjectNumber(4)));
        self::assertInstanceOf(StringType::class, $value);
        self::assertSame('newer revision', $value->value);
    }

    public function testBothClassicAndAuxiliaryEntriesConsumeTheSameCumulativeBudget(): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxCrossReferenceEntries: 6)))->read(new FileBytes($this->hybrid()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidAuxiliary(): iterable
    {
        yield 'recursive stream link' => [' /XRefStm 1', ''];
        yield 'stream Size exceeds table' => [' /Size 7', ''];
        yield 'stream Root never replaces table Root' => [' /Root 3 0 R', ' /Root 3 0 R'];
    }

    #[DataProvider('invalidAuxiliary')]
    public function testInvalidAuxiliaryStructureIsRejected(string $streamExtra, string $trailerExtra): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader())->read(new FileBytes($this->hybrid(streamExtra: $streamExtra, trailerExtra: $trailerExtra)));
    }

    private function hybrid(int $type = 2, string $streamExtra = '', string $trailerExtra = ''): string
    {
        $bytes = "%PDF-1.7\n";
        $payload = '4 0 (compressed wins)';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R /Marker 4 0 R >>',
            2 => '<< /Type /Pages /Kids [] /Count 0 >>',
            3 => '<< /Type /ObjStm /N 1 /First 4 /Length ' . strlen($payload) . ">>\nstream\n" . $payload . "\nendstream",
            4 => '(table loses)',
        ];
        $offsets = [0 => 0];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= "$number 0 obj\n$value\nendobj\n";
        }
        $offsets[5] = strlen($bytes);
        $entry = pack('CNN', $type, $type === 0 ? 0 : 3, 0);
        $entry = $entry[0] . substr($entry, 1, 4) . substr($entry, -2);
        $bytes .= "5 0 obj\n<< /Type /XRef /Size 6 /W [1 4 2] /Index [4 1] /Length 7 /Prev 999999"
            . $streamExtra . ">>\nstream\n" . $entry . "\nendstream\nendobj\n";
        $xref = strlen($bytes);
        $bytes .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (range(1, 5) as $number) {
            $bytes .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        return $bytes . 'trailer << /Size 6 /Root 1 0 R /XRefStm ' . $offsets[5] . $trailerExtra
            . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
