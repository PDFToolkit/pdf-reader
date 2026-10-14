<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeReader;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingPdf20Test extends TestCase
{
    /** @return iterable<string, array{string, PdfVersion}> */
    public static function fixtures(): iterable
    {
        yield 'PDF 2.0 header' => ['header.pdf', PdfVersion::PDF20];
        yield 'PDF 1.7 with 2.0 catalog' => ['catalog.pdf', PdfVersion::PDF17];
        yield 'PDF 2.0 object streams' => ['object-streams.pdf', PdfVersion::PDF20];
    }

    #[DataProvider('fixtures')]
    public function testIndependentPdf20Syntax(string $name, PdfVersion $header): void
    {
        $document = (new DocumentReader())->read(new FileBytes($this->fixture($name)));
        self::assertSame($header, $document->header()->version);
        self::assertCount(1, (new PageTreeReader($document))->read());
        $text = $document->catalog()->get('FixtureUTF8');
        self::assertInstanceOf(StringType::class, $text);
        self::assertSame('Bonjour été', (new TextStringDecoder(PdfVersion::PDF20))->decode($text));
        if ($name === 'catalog.pdf') {
            self::assertInstanceOf(NameType::class, $document->catalog()->get('Version'));
            self::assertSame('2.0', $document->catalog()->get('Version')->value);
        }
    }

    public function testFutureVersionIsNotSilentlyAccepted(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->read(new FileBytes(str_replace('%PDF-2.0', '%PDF-2.1', $this->fixture('header.pdf'))));
    }

    public function testFutureCatalogVersionIsNotSilentlyAccepted(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->read(new FileBytes(str_replace('/2.0', '/2.1', $this->fixture('catalog.pdf'))));
    }

    public function testEncryptionRemainsExplicitlyUnsupported(): void
    {
        $bytes = $this->fixture('header.pdf');
        $trailer = strrpos($bytes, 'trailer');
        self::assertNotFalse($trailer);
        $bytes = substr_replace($bytes, '/Encrypt 1 0 R ', strpos($bytes, '<<', $trailer) + 2, 0);
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->read(new FileBytes($bytes));
    }

    private function fixture(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf20/' . $name);
        self::assertNotFalse($bytes);
        return $bytes;
    }
}
