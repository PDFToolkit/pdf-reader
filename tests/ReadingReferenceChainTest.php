<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\SecurityDictionaryValues;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Hand-written physical indirect objects, independently of the PDFToolkit writer. */
final class ReadingReferenceChainTest extends TestCase
{
    public function testRootAndPermissionChainsResolveToTheirTerminalValues(): void
    {
        $document = (new DocumentReader())->read($this->source());
        self::assertSame(-4, (new SecurityDictionaryValues($document, $document->catalog()))->integer('P'));
        self::assertInstanceOf(NullType::class, $document->resolve($document->catalog()->get('Optional')));
    }

    #[DataProvider('nullTargets')]
    public function testUnavailableGenerationsAndObjectsTerminateAsNull(string $target): void
    {
        $document = (new DocumentReader())->read($this->source([6 => $target]));
        self::assertInstanceOf(NullType::class, $document->resolve($document->catalog()->get('Optional')));
    }

    /** @return iterable<string, array{string}> */
    public static function nullTargets(): iterable
    {
        yield 'missing object' => ['99 0 R'];
        yield 'unavailable generation' => ['7 1 R'];
    }

    public function testAReferenceCycleFailsExplicitly(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('indirect-reference chain');
        (new DocumentReader())->read($this->source([1 => '2 0 R', 2 => '1 0 R']));
    }

    public function testChainDepthUsesTheConfiguredDocumentLimit(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('depth limit');
        (new DocumentReader(new ReaderLimits(maxDepth: 3)))->read($this->source([1 => '4 0 R', 5 => '6 0 R', 7 => '2 0 R']));
    }

    /** @param array<int, string> $overrides */
    private function source(array $overrides = []): FileBytes
    {
        $objects = $overrides + [
            1 => '2 0 R',
            2 => '<< /Type /Catalog /Pages 3 0 R /P 4 0 R /Optional 6 0 R >>',
            3 => '<< /Type /Pages /Kids [] /Count 0 >>',
            4 => '5 0 R',
            5 => '-4',
            6 => '7 0 R',
            7 => 'null',
        ];
        ksort($objects);
        $bytes = "%PDF-2.0\n";
        $offsets = [];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $value . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= "xref\n0 8\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        return new FileBytes($bytes . "trailer << /Size 8 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
    }
}
