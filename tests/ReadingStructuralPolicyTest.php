<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\StructuralPolicy;
use PDF\Reading\SyntaxParser;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingStructuralPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function noncanonicalReferences(): iterable
    {
        yield 'object leading zero' => ['01 0 R'];
        yield 'generation leading zero' => ['1 00 R'];
        yield 'both padded' => ['0001 0002 R'];
    }

    #[DataProvider('noncanonicalReferences')]
    public function testCanonicalIdentifiersAreAnExplicitPdf20Choice(string $source): void
    {
        self::assertInstanceOf(ObjectReference::class, (new SyntaxParser(new ByteCursor($source)))->read());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('canonical decimal digits');
        (new SyntaxParser(new ByteCursor($source), structuralPolicy: StructuralPolicy::Pdf20))->read();
    }

    public function testGenericIntegerSyntaxAndFixedWidthXrefsKeepTheirExistingMeaning(): void
    {
        $value = (new SyntaxParser(new ByteCursor('+001'), structuralPolicy: StructuralPolicy::Pdf20))->read();
        self::assertInstanceOf(IntegerType::class, $value);
        self::assertSame(1, $value->integer());
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($this->source())->catalog());
    }

    public function testAnOrdinaryObjectHeaderUsesTheSelectedPolicy(): void
    {
        $source = $this->source('01');
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->catalog());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('canonical decimal digits');
        (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
    }

    public function testPhysicalHistoryUsesTheSameExplicitStructuralPolicy(): void
    {
        $source = $this->source('01');
        self::assertCount(1, (new DocumentReader())->readRevisionHistory($source));
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('canonical decimal digits');
        (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->readRevisionHistory($source);
    }

    public function testStrengtheningSourceIntegrityPreservesStructuralPolicy(): void
    {
        $reader = (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->requiringSourceIntegrity(SourceIntegrityPolicy::RequirePdfMac);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('canonical decimal digits');
        $reader->read($this->source(reference: '01'));
    }

    private function source(string $number = '1', string $reference = '1'): FileBytes
    {
        $bytes = "%PDF-1.7\n";
        $first = strlen($bytes);
        $bytes .= $number . " 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $second = strlen($bytes);
        $bytes .= "2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\n";
        $xref = strlen($bytes);
        $bytes .= "xref\n0 3\n0000000000 65535 f \n" . sprintf("%010d 00000 n \n%010d 00000 n \n", $first, $second);
        return new FileBytes($bytes . "trailer\n<< /Size 3 /Root " . $reference . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
    }
}
