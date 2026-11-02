<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\Inspection\InspectionLimits;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Reading\Tests\Support\ExternalSourceProbe;
use PDF\Reading\Tests\Support\ImportSource;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingInspectionTest extends TestCase
{
    public function testOriginalStructureReferencesRolesAndPageInheritance(): void
    {
        $document = $this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R >>',
            3 => '<< /Type /StructTreeRoot /K 4 0 R /RoleMap << /Custom /P >> /Namespaces [8 0 R 9 0 R] >>',
            4 => '<< /Type /StructElem /S /Custom /P 3 0 R /Pg 90 0 R /NS 8 0 R /A 7 0 R /K [0 5 0 R 6 0 R] >>',
            5 => '<< /Type /MCR /MCID 1 >>',
            6 => '<< /Type /OBJR /Obj 10 2 R >>',
            7 => '<< /O /Layout /Placement /Block >>',
            8 => '<< /Type /Namespace /NS (same-uri) /RoleMapNS << /Custom [/P 9 0 R] >> >>',
            9 => '<< /Type /Namespace /NS (same-uri) /RoleMapNS << /P /P >> >>',
            10 => '<< /Type /Annot /Subtype /Link >>',
        ], [10 => 2]);
        $before = $document->catalog();
        $snapshot = $this->inspect($document);
        self::assertSame(1, $snapshot['schemaVersion']);
        self::assertTrue($snapshot['document']['tagged']);
        self::assertFalse($snapshot['truncated']);
        self::assertSame([], $snapshot['diagnostics']);
        self::assertSame(['node-1'], $snapshot['roots']);
        self::assertSame(['element', 'mcid', 'mcr', 'objr'], array_column($snapshot['nodes'], 'kind'));
        self::assertSame(['node-2', 'node-3', 'node-4'], $snapshot['nodes'][0]['children']);
        self::assertSame(['number' => '10', 'generation' => 2], $snapshot['nodes'][3]['objectReference']);
        self::assertSame(['number' => 1, 'reference' => ['number' => '90', 'generation' => 0], 'inherited' => false], $snapshot['nodes'][0]['page']);
        self::assertTrue($snapshot['nodes'][2]['page']['inherited']);
        self::assertArrayNotHasKey('Pg', $this->entries($snapshot['nodes'][2]['properties']));
        self::assertSame('/Custom', $snapshot['nodes'][0]['role']['preview']);
        self::assertSame('obj-8-0', $snapshot['nodes'][0]['namespace']['identity']);
        self::assertSame(['structure-root', 'obj-8-0', 'obj-9-0'], array_column($snapshot['roleMappings'], 'owner'));
        $properties = $this->entries($snapshot['nodes'][0]['properties']);
        self::assertSame('reference', $properties['A']['type']);
        self::assertSame('obj-7-0', $properties['A']['target']);
        $objects = array_column($snapshot['objects'], null, 'id');
        self::assertSame('/Layout', $this->entries($objects['obj-7-0']['value'])['O']['preview']);
        self::assertSame($before, $document->catalog());
        self::assertSame($snapshot, $this->inspect($document));
    }

    public function testByteStringsNamesIntegersAndOriginalTypesSurviveJson(): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /Binary <00fffe> /Hostile (<script>alert(1)</script>) /#FF#2F /#FE /Big 9007199254740993 /Real 1.0 /Flag false /Nothing null /Values [1 1.5 true (text)] >>',
        ]));
        $entries = $this->entries($snapshot['document']['catalog']);
        self::assertSame('00fffe', $entries['Binary']['hex']);
        self::assertSame('hexadecimal', $entries['Binary']['syntax']);
        self::assertSame('<script>alert(1)</script>', $entries['Hostile']['preview']);
        self::assertSame('/#FE', $entries["\xff/"]['preview']);
        self::assertSame('9007199254740993', $entries['Big']['value']);
        self::assertSame('integer', $entries['Big']['type']);
        self::assertSame('real', $entries['Real']['type']);
        self::assertFalse($entries['Flag']['value']);
        self::assertNull($entries['Nothing']['value']);
        self::assertSame(['integer', 'real', 'boolean', 'string'], array_column($entries['Values']['items'], 'type'));
    }

    public function testNoStreamsDecodedAndNoExternalFileAcquired(): void
    {
        $probe = new ExternalSourceProbe('must never be read');
        $source = new ImportSource();
        $document = (new DocumentReader(externalStreams: $probe))->read($source->file([
            1 => '<< /Type /Catalog /Pages 2 0 R /AF [3 0 R] /Names << /EmbeddedFiles << /Names [(key) 3 0 R] >> >> >>',
            3 => '<< /Type /Filespec /F (source.xml) /UF <feff0073006f0075007200630065002e0078006d006c> /AFRelationship /Source /EF << /F 4 0 R >> >>',
            4 => $source->stream('not a valid compressed stream', '/Type /EmbeddedFile /Subtype /application#2Fxml /Filter /NotImplemented /F (https://example.invalid/data)'),
        ]));
        $snapshot = $this->inspect($document);
        self::assertSame([], $probe->requests);
        self::assertSame([], $snapshot['diagnostics']);
        self::assertCount(2, $snapshot['associatedFiles']);
        self::assertSame(['catalog', 'embedded-files'], array_column(array_column($snapshot['associatedFiles'], 'owner'), 'kind'));
        self::assertSame('source.xml', $snapshot['associatedFiles'][0]['filename']);
        self::assertSame('Source', $snapshot['associatedFiles'][0]['relationship']);
        self::assertSame(strlen('not a valid compressed stream'), $snapshot['associatedFiles'][0]['embeddedStreams'][0]['encodedByteLength']);
        $objects = array_column($snapshot['objects'], null, 'id');
        self::assertSame('stream', $objects['obj-4-0']['value']['type']);
        self::assertArrayNotHasKey('bytes', $objects['obj-4-0']['value']);
    }

    public function testUntitledUntaggedPdfRemainsInspectable(): void
    {
        $snapshot = $this->inspect($this->document());
        self::assertFalse($snapshot['document']['tagged']);
        self::assertNull($snapshot['document']['structureRoot']);
        self::assertSame([], $snapshot['roots']);
        self::assertSame([], $snapshot['nodes']);
        self::assertCount(1, $snapshot['pages']);
        self::assertFalse($snapshot['truncated']);
    }

    public function testCyclesRepeatedNodesAndMalformedChildrenStayVisible(): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R >>',
            3 => '<< /Type /StructTreeRoot /K [4 0 R 4 0 R true] >>',
            4 => '<< /Type /StructElem /S /P /P 3 0 R /K [4 0 R 5 0 R] >>',
            5 => '[5 0 R]',
        ]));
        self::assertSame(['element', 'cycle', 'cycle', 'repeated', 'invalid'], array_column($snapshot['nodes'], 'kind'));
        self::assertSame(['structure-cycle', 'structure-cycle', 'structure-repeated', 'structure-invalid'], array_column($snapshot['diagnostics'], 'code'));
        self::assertFalse($snapshot['truncated']);
    }

    public function testBrokenReferenceIsDifferentFromAnActualNullObject(): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /Missing 88 0 R /WrongGeneration 3 1 R /ActualNull 4 0 R >>',
            3 => '(not accessible under generation one)',
            4 => 'null',
        ]));
        $objects = array_column($snapshot['objects'], null, 'id');
        self::assertSame('missing', $objects['obj-88-0']['status']);
        self::assertSame('missing', $objects['obj-3-1']['status']);
        self::assertSame('available', $objects['obj-4-0']['status']);
        self::assertSame('null', $objects['obj-4-0']['value']['type']);
    }

    public function testIndirectReferenceChainKeepsEveryPhysicalIdentity(): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /Custom 3 0 R >>',
            3 => '4 0 R',
            4 => '<< /Key (value) >>',
        ]));
        $objects = array_column($snapshot['objects'], null, 'id');
        self::assertSame('reference', $objects['obj-3-0']['value']['type']);
        self::assertSame('obj-4-0', $objects['obj-3-0']['value']['target']);
        self::assertSame('dictionary', $objects['obj-4-0']['value']['type']);
    }

    public function testMalformedPageTreeDoesNotHideStructureProperties(): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R >>',
            2 => '<< /Type /Pages /Kids [90 0 R] /Count 2 >>',
            3 => '<< /Type /StructTreeRoot /K << /Type /StructElem /S /P /Pg 90 0 R >> >>',
        ]));
        self::assertSame([], $snapshot['pages']);
        self::assertTrue($snapshot['truncated']);
        self::assertCount(1, $snapshot['nodes']);
        self::assertNull($snapshot['nodes'][0]['page']['number']);
        self::assertContains('pages-unavailable', array_column($snapshot['diagnostics'], 'code'));
    }

    public static function limits(): iterable
    {
        yield 'nodes' => [new InspectionLimits(maxNodes: 1), 'limit-nodes'];
        yield 'objects' => [new InspectionLimits(maxObjects: 1), 'limit-objects'];
        yield 'values' => [new InspectionLimits(maxValues: 1), 'limit-values'];
        yield 'bytes' => [new InspectionLimits(maxBytes: 1), 'limit-bytes'];
        yield 'strings' => [new InspectionLimits(maxStringBytes: 1), 'limit-string'];
        yield 'depth' => [new InspectionLimits(maxDepth: 1), 'limit-depth'];
        yield 'associations' => [new InspectionLimits(maxAssociations: 1), 'limit-associations'];
    }

    #[DataProvider('limits')]
    public function testEveryCumulativeCeilingProducesExplicitPartialResult(InspectionLimits $limits, string $code): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R /AF [(first.txt) (second.txt)] >>',
            3 => '<< /Type /StructTreeRoot /K << /Type /StructElem /S /Document /K << /Type /StructElem /S /P /K [0 1 2] >> >> >>',
        ]), $limits);
        self::assertTrue($snapshot['truncated']);
        self::assertContains($code, array_column($snapshot['diagnostics'], 'code'));
    }

    public function testOversizedNamesAndStringsDeclareTheirOriginalLength(): void
    {
        $snapshot = $this->inspect($this->document([1 => '<< /Type /Catalog /Pages 2 0 R /Long (abcdefghij) /Name /abcdefghij >>']), new InspectionLimits(maxStringBytes: 8));
        $entries = $this->entries($snapshot['document']['catalog']);
        self::assertSame('6162636465666768', $entries['Long']['hex']);
        self::assertSame(10, $entries['Long']['byteLength']);
        self::assertNull($entries['Long']['preview']);
        self::assertTrue($entries['Long']['truncated']);
        self::assertSame('/abcdefgh', $entries['Name']['preview']);
    }

    public function testInvalidUtf16HasExactBytesAndNoFabricatedText(): void
    {
        $snapshot = $this->inspect($this->document([1 => '<< /Type /Catalog /Pages 2 0 R /Invalid <feffd800> /Utf8 <efbbbfcebb> >>']));
        $entries = $this->entries($snapshot['document']['catalog']);
        self::assertNull($entries['Invalid']['preview']);
        self::assertSame('feffd800', $entries['Invalid']['hex']);
        self::assertSame('λ', $entries['Utf8']['preview']);
    }

    public function testDiagnosticCountIsBounded(): void
    {
        $snapshot = $this->inspect($this->document([1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot << /K [true false /Wrong 8 0 R] >> >>']), new InspectionLimits(maxDiagnostics: 1));
        self::assertCount(1, $snapshot['diagnostics']);
        self::assertTrue($snapshot['truncated']);
    }

    public function testInvalidLimitRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InspectionLimits(maxDepth: 0);
    }

    public function testMalformedRecognizableStructuresExposeSpecificDiagnostics(): void
    {
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot << /K [<< /Type /StructElem >> << /Type /MCR /MCID -1 >> << /Type /OBJR >>] >> >>',
        ]));
        self::assertSame(['element', 'mcr', 'objr'], array_column($snapshot['nodes'], 'kind'));
        self::assertSame(['invalid-structure-role', 'invalid-mcr', 'invalid-objr'], array_column($snapshot['diagnostics'], 'code'));
        self::assertSame('-1', $snapshot['nodes'][1]['mcid']);
    }

    public function testRepeatedNamespaceLabelsAreChargedOnEveryPresentation(): void
    {
        $children = str_repeat('<< /Type /StructElem /S /P /NS 5 0 R >> ', 40);
        $snapshot = $this->inspect($this->document([
            1 => '<< /Type /Catalog /Pages 2 0 R /StructTreeRoot 3 0 R >>',
            3 => '<< /K [' . $children . '] >>',
            5 => '<< /Type /Namespace /NS (' . str_repeat('n', 2000) . ') >>',
        ]), new InspectionLimits(maxBytes: 20000));
        self::assertTrue($snapshot['truncated']);
        self::assertContains('limit-bytes', array_column($snapshot['diagnostics'], 'code'));
        self::assertLessThan(22000, strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)));
    }

    public function testAuthenticatedPermissionsArePreservedWithoutGrantingOwnerAccess(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/encryption/qpdf-r3-128-metadata-encrypted.pdf');
        self::assertNotFalse($bytes);
        $masks = [];
        foreach (['user', 'owner'] as $password) {
            $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PdfDocEncodedPassword(new HexadecimalString($password)))))->read(new FileBytes($bytes));
            $snapshot = $this->inspect($document);
            self::assertSame($document->permissions()->effectiveMask(), $snapshot['document']['authenticatedPermissions']);
            $masks[] = $snapshot['document']['authenticatedPermissions'];
        }
        self::assertNotSame($masks[0], $masks[1]);
    }

    public function testPageLimitDoesNotSuppressOriginalCatalog(): void
    {
        $snapshot = $this->inspect($this->document([
            2 => '<< /Type /Pages /Kids [90 0 R 91 0 R] /Count 2 >>',
            91 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources <<>> >>',
        ]), new InspectionLimits(maxPages: 1));
        self::assertSame([], $snapshot['pages']);
        self::assertTrue($snapshot['truncated']);
        self::assertSame('dictionary', $snapshot['document']['catalog']['type']);
    }

    public function testInvalidCatalogVersionIsRejectedBeforeInspectionJson(): void
    {
        $this->expectException(\PDF\Reading\UnsupportedPdfFeature::class);
        $this->inspect($this->document([1 => '<< /Type /Catalog /Pages 2 0 R /Version /#FF >>']));
    }

    /** @param array<int, string> $objects
     * @param array<int, int> $generations
     */
    private function document(array $objects = [], array $generations = []): ReadDocument
    {
        return (new DocumentReader())->read((new ImportSource())->file($objects, $generations));
    }

    private function inspect(ReadDocument $document, InspectionLimits $limits = new InspectionLimits()): array
    {
        return json_decode(json_encode((new DocumentInspector($limits))->inspect($document), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function entries(array $dictionary): array
    {
        $entries = [];
        foreach ($dictionary['entries'] as $entry) {
            $entries[hex2bin($entry['key']['hex'])] = $entry['value'];
        }
        return $entries;
    }
}
