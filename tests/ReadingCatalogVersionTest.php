<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Catalog Version is an optional name without a direct-object restriction (ISO 32000-2 Table 29). */
final class ReadingCatalogVersionTest extends TestCase
{
    public function testAnIndirectCatalogVersionNameIsAcceptedWithoutRewritingTheHeader(): void
    {
        $document = (new DocumentReader())->read($this->file('3 0 R', '/2.0'));
        self::assertSame(PdfVersion::PDF17, $document->header()->version);
        $reference = $document->catalog()->get('Version');
        self::assertNotNull($reference);
        $version = $document->resolve($reference);
        self::assertInstanceOf(NameType::class, $version);
        self::assertSame('2.0', $version->value);
    }

    #[DataProvider('nullVersions')]
    public function testNullCatalogVersionIsEquivalentToItsAbsence(string $entry): void
    {
        $document = (new DocumentReader())->read($this->file($entry, 'null'));
        self::assertInstanceOf(DictionaryType::class, $document->catalog());
        self::assertSame(PdfVersion::PDF17, $document->header()->version);
        $reference = $document->catalog()->get('Version');
        self::assertNotNull($reference);
        self::assertInstanceOf(NullType::class, $document->resolve($reference));
    }

    /** @return iterable<string, array{string}> */
    public static function nullVersions(): iterable
    {
        yield 'direct null' => ['null'];
        yield 'null object' => ['3 0 R'];
        yield 'missing reference' => ['99 0 R'];
        yield 'outdated generation' => ['3 1 R'];
    }

    #[DataProvider('invalidVersions')]
    public function testUnsupportedIndirectVersionNamesAndNonNameValuesAreRejected(string $value, bool $indirect): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        $this->expectExceptionMessage('supported PDF 1.0–1.7 or 2.0 version');
        (new DocumentReader())->read($this->file($indirect ? '3 0 R' : $value, $value));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidVersions(): iterable
    {
        foreach ([false, true] as $indirect) {
            foreach (['future version' => '/2.1', 'number' => '2.0', 'string' => '(2.0)', 'boolean' => 'true', 'array' => '[/2.0]', 'dictionary' => '<< /Version /2.0 >>'] as $name => $value) {
                yield ($indirect ? 'indirect' : 'direct') . '/' . $name => [$value, $indirect];
            }
        }
    }

    private function file(string $version, string $value): FileBytes
    {
        $bytes = "%PDF-1.7\n";
        $offsets = [];
        foreach ([
            1 => '<< /Type /Catalog /Pages 2 0 R /Version ' . $version . ' >>',
            2 => '<< /Type /Pages /Kids [] /Count 0 >>',
            3 => $value,
        ] as $number => $object) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        return new FileBytes($bytes . "trailer << /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
    }
}
