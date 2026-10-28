<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReaderLimits;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingLinearizedHistoryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function layouts(): iterable
    {
        yield 'traditional sections and dummy footer' => ['table'];
        yield 'stream sections without a dummy footer' => ['stream'];
    }

    #[DataProvider('layouts')]
    public function testTwoLinearizedSectionsAreOneExactPhysicalRevision(string $kind): void
    {
        $source = $this->fixture($kind);
        $history = (new DocumentReader(new ReaderLimits(maxRevisions: 1)))->readRevisionHistory($source);
        self::assertCount(1, $history);
        $revision = $history->latest();
        self::assertSame($source->bytes, $revision->source()->bytes);
        $parts = $revision->crossReference->linearized;
        self::assertNotNull($parts);
        self::assertSame(strlen($source->bytes), $parts->declaredLength);
        self::assertSame($parts->main->section->offset->value, $revision->crossReference->section->previous?->value);
        self::assertNull($parts->main->section->previous);
        self::assertCount(2, $revision->crossReference->physicalParts());
        self::assertSame($kind === 'table', $parts->firstPageFooter !== null);
        self::assertNull($revision->rawDocument()->permissions());
        $binary = $revision->rawDocument()->catalog()->get('FixtureBinary');
        self::assertInstanceOf(StringType::class, $binary);
        self::assertSame(implode('', array_map(chr(...), range(0, 255))), $binary->value);
    }

    #[DataProvider('layouts')]
    public function testAnIncrementPreservesTheCompleteLinearizedPrefix(string $kind): void
    {
        $source = $this->fixture($kind);
        $initial = (new DocumentReader())->readRevisionHistory($source)->latest();
        $size = $initial->rawDocument()->trailer()->get('Size');
        $root = $initial->rawDocument()->trailer()->get('Root');
        self::assertInstanceOf(IntegerType::class, $size);
        self::assertInstanceOf(ObjectReference::class, $root);
        $object = $size->integer();
        $bytes = $source->bytes . $object . " 0 obj\n<< /Title (Updated) >>\nendobj\n";
        $offset = strlen($bytes);
        $bytes .= "xref\n" . $object . " 1\n" . sprintf("%010d 00000 n \n", strlen($source->bytes))
            . 'trailer << /Size ' . ($object + 1) . ' /Root ' . $root->identifier . ' R /Info ' . $object
            . ' 0 R /Prev ' . $initial->crossReference->section->offset->value . " >>\nstartxref\n" . $offset . "\n%%EOF\n";
        $history = (new DocumentReader(new ReaderLimits(maxRevisions: 2)))->readRevisionHistory(new FileBytes($bytes));
        self::assertCount(2, $history);
        self::assertSame($source->bytes, iterator_to_array($history)[0]->source()->bytes);
        self::assertNull($history->latest()->crossReference->linearized);
        self::assertSame($bytes, $history->latest()->source()->bytes);
        self::assertSame(
            $initial->rawDocument()->catalog()->get('FixtureBinary')->value,
            $history->latest()->rawDocument()->catalog()->get('FixtureBinary')->value
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidParameters(): iterable
    {
        yield 'unknown linearization version' => ['/Linearized 1', '/Linearized 2'];
        yield 'nonpositive page count' => ['/N 1', '/N 0'];
        yield 'out-of-bounds hint range' => ['/H [ 1281 118 ]', '/H [ 9999 118 ]'];
        yield 'out-of-bounds declared initial length' => ['/L 2537', '/L 9999'];
        yield 'dummy footer cannot claim a section' => ["startxref\n0\n%%EOF", "startxref\n1\n%%EOF"];
        yield 'forward Prev is exact, not preceding whitespace' => ['/Prev 2311', '/Prev 2310'];
    }

    #[DataProvider('invalidParameters')]
    public function testMalformedLinearizedProvenanceIsRejected(string $find, string $replace): void
    {
        $source = $this->fixture('table')->bytes;
        self::assertSame(1, substr_count($source, $find));
        self::assertSame(strlen($find), strlen($replace));
        $this->expectException(ParseException::class);
        (new DocumentReader())->readRevisionHistory(new FileBytes(str_replace($find, $replace, $source)));
    }

    #[DataProvider('layouts')]
    public function testBothSectionsShareTheCrossReferenceBudget(string $kind): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxCrossReferenceEntries: 6)))->readRevisionHistory($this->fixture($kind));
    }

    private function fixture(string $kind): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/revision-history/linearized-' . $kind . '.pdf');
        self::assertIsString($bytes);
        return new FileBytes($bytes);
    }
}
