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
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
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

    public function testAnyObjStmDictionaryValueCannotReferenceACompressedObjectInPdf20(): void
    {
        $source = $this->compressedSource('/Private 5 0 R');
        $member = new ObjectIdentifier(new ObjectNumber(5));
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->object($member));
        $document = (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('ObjStm dictionary value');
        $document->object($member);
    }

    public function testObjStmDirectContainersAndUncompressedReferencesRemainAllowed(): void
    {
        $source = $this->compressedSource('/Private 2 0 R /DirectArray [5 0 R]');
        $document = (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
        self::assertInstanceOf(DictionaryType::class, $document->object(new ObjectIdentifier(new ObjectNumber(5))));
    }

    public function testCompressedMemberIdentifiersUseTheExplicitCanonicalPolicy(): void
    {
        $source = $this->compressedSource(member: '05');
        $member = new ObjectIdentifier(new ObjectNumber(5));
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->object($member));
        $document = (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('canonical decimal digits');
        $document->object($member);
    }

    public function testTheDecodedLengthHintInAnXrefStreamMustBeDirectUnderPdf20(): void
    {
        $source = $this->compressedSource(xrefDictionary: '/DL 5 0 R');
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->catalog());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('recursively direct');
        (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
    }

    public function testUnusedNestedFilterParametersCannotHideIndirectXrefValues(): void
    {
        $source = $this->compressedSource(xrefDictionary: '/Filter /FlateDecode /DecodeParms << /Ignored [<< /Other 5 0 R >>] >>', flate: true);
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->catalog());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('recursively direct');
        (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
    }

    public function testDirectNestedFilterParametersAndIndirectTrailerRootRemainPermitted(): void
    {
        $source = $this->compressedSource(xrefDictionary: '/Filter /FlateDecode /DecodeParms << /Ignored [<< /Other 17 >>] >> /DL 49 /F null', flate: true);
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source)->catalog());
    }

    public function testExternalDataIsForbiddenInPdf20CrossReferences(): void
    {
        $source = $this->compressedSource(xrefDictionary: '/F (external.bin)');
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('cannot contain F');
        (new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20))->read($source);
    }

    private function compressedSource(string $dictionary = '', string $xrefDictionary = '', string $member = '5', bool $flate = false): FileBytes
    {
        $header = $member . ' 0 ';
        $body = $header . '<< /Value (member) >>';
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [] /Count 0 >>',
            4 => '<< /Type /ObjStm /N 1 /First ' . strlen($header) . ' /Length ' . strlen($body) . ' ' . $dictionary . ">>\nstream\n" . $body . "\nendstream"];
        $bytes = "%PDF-2.0\n";
        $offsets = [];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $value . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $offsets[6] = $xref;
        $data = '';
        for ($number = 0; $number < 7; ++$number) {
            $record = $number === 5 ? [2, 4, 0] : (isset($offsets[$number]) ? [1, $offsets[$number], 0] : [0, 0, $number === 0 ? 65535 : 0]);
            $data .= pack('CNn', ...$record);
        }
        if ($flate) {
            $compressed = gzcompress($data);
            self::assertIsString($compressed);
            $data = $compressed;
        }
        return new FileBytes($bytes . '6 0 obj << /Type /XRef /Size 7 /Root 1 0 R /W [1 4 2] /Length ' . strlen($data) . ' ' . $xrefDictionary
            . ">>\nstream\n" . $data . "\nendstream\nendobj\nstartxref\n" . $xref . "\n%%EOF\n");
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
