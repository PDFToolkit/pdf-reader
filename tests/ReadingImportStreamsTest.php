<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\FileStructure;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Import\ImportException;
use PDF\Reading\Import\ImportLimits;
use PDF\Reading\Import\ImportSession;
use PDF\Reading\Import\StreamImportMode;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Reading\Tests\Support\ExternalSourceProbe;
use PDF\Reading\Tests\Support\ImportSource;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingImportStreamsTest extends TestCase
{
    public function testEncodedChainAndIndirectDecodeParameterGraphArePreservedExactly(): void
    {
        $bytes = bin2hex(gzcompress('q Q')) . '>';
        $document = $this->document($bytes, '/Filter [/ASCIIHexDecode /FlateDecode] /DecodeParms [null 4 0 R]', [4 => '<< /Predictor 1 /Reference 5 0 R >>', 5 => '(parameter data)']);
        $native = $this->import($document);
        self::assertSame($bytes, $native->bytes(new SerializationContext()));
        self::assertSame(['ASCIIHexDecode', 'FlateDecode'], array_map(static fn ($filter): string => $filter->name()->value, $native->filters));
        self::assertSame('parameter data', $native->filters[1]->parameters()->get('Reference')->target()->value);
        $copy = $this->readNative($document);
        self::assertSame('q Q', $copy->streamData($copy->resolve($copy->catalog()->get('Data'))));
        self::assertSame($bytes, $copy->resolve($copy->catalog()->get('Data'))->encodedBytes());
    }

    public function testExplicitDecodeRemovesTheCodecAfterProducingBoundedPlaintext(): void
    {
        $document = $this->document(gzcompress('q Q'), '/Filter /FlateDecode');
        $native = $this->import($document, StreamImportMode::Decode);
        self::assertSame('q Q', $native->data);
        self::assertSame([], $native->filters);
    }

    public function testOpaqueCodecIsPreservedWithoutExecutingAnUnavailableEngine(): void
    {
        $document = $this->document('opaque JBIG2 bytes', '/Filter /JBIG2Decode /DecodeParms << /JBIG2Globals 4 0 R >>', [4 => (new ImportSource())->stream('globals')]);
        $native = $this->import($document);
        self::assertSame('opaque JBIG2 bytes', $native->data);
        self::assertSame('JBIG2Decode', $native->filters[0]->name()->value);
        self::assertSame('globals', $native->filters[0]->parameters()->get('JBIG2Globals')->target()->data);
        $this->expectException(UnsupportedPdfFeature::class);
        $this->import($document, StreamImportMode::Decode);
    }

    public function testXmlMetadataAlwaysUsesDecodedBytesForTheWriterUtf8Evidence(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><x>été</x>';
        $document = $this->document(gzcompress($xml), '/Type /Metadata /Subtype /XML /Filter /FlateDecode');
        $native = $this->import($document);
        self::assertSame($xml, $native->data);
        self::assertSame([], $native->filters);
        $copy = $this->readNative($document);
        self::assertSame($xml, $copy->resolve($copy->catalog()->get('Data'))->encodedBytes());
    }

    public function testInvalidMetadataUtf8IsNotHiddenBehindAnOpaqueFilter(): void
    {
        $document = $this->document(gzcompress("<x>\xff</x>"), '/Type /Metadata /Subtype /XML /Filter /FlateDecode');
        $this->expectException(\InvalidArgumentException::class);
        $this->import($document);
    }

    public function testCatalogVersionUpgradeChecksSourceMetadataAsPdf20(): void
    {
        $fixture = new ImportSource();
        $bytes = $fixture->file([
            1 => '<< /Type /Catalog /Version /2.0 /Pages 2 0 R /Metadata 3 0 R >>',
            3 => $fixture->stream('<x>é</x>', '/Type /Metadata /Subtype /XML'),
        ])->bytes;
        $document = (new DocumentReader())->read(new FileBytes(str_replace('%PDF-2.0', '%PDF-1.7', $bytes)));
        $graph = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->catalog()->get('Metadata'));
        self::assertSame('<x>é</x>', $graph->root()->target()->data);
        $invalid = str_replace('é', "\xff\xff", $bytes);
        $document = (new DocumentReader())->read(new FileBytes(str_replace('%PDF-2.0', '%PDF-1.7', $invalid)));
        $this->expectException(\InvalidArgumentException::class);
        (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->catalog()->get('Metadata'));
    }

    public function testPdf17MetadataRemainsDecodedAndCannotForgeAnUpgradeProof(): void
    {
        $fixture = new ImportSource();
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?><x/>';
        $bytes = $fixture->file([
            1 => '<< /Type /Catalog /Pages 2 0 R /Metadata 3 0 R >>',
            3 => $fixture->stream(gzcompress($xml), '/Type /Metadata /Subtype /XML /Filter /FlateDecode'),
        ])->bytes;
        $document = (new DocumentReader())->read(new FileBytes(str_replace('%PDF-2.0', '%PDF-1.7', $bytes)));
        $root = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->trailer()->get('Root'))->root();
        self::assertSame($xml, $root->target()->get('Metadata')->target()->data);
        self::assertStringStartsWith('%PDF-1.7', (new FileStructure(new FileHeader('1.7'), new FileBody(), $root))->toPdf());
        $this->expectException(\InvalidArgumentException::class);
        (new FileStructure(new FileHeader('2.0'), new FileBody(), $root))->toPdf();
    }

    public function testIdentityCryptIsRemovedWithoutCopyingSourceSecurity(): void
    {
        $document = $this->document(gzcompress('identity'), '/Filter [/Crypt /FlateDecode] /DecodeParms [<< /Name /Identity >> null]');
        $native = $this->import($document);
        self::assertSame(gzcompress('identity'), $native->data);
        self::assertCount(1, $native->filters);
        self::assertSame('FlateDecode', $native->filters[0]->name()->value);
    }

    #[DataProvider('badFilters')]
    public function testMalformedFilterContractsAndUnresolvedCryptAreRejected(string $entries): void
    {
        $this->expectException(ImportException::class);
        $this->import($this->document('opaque', $entries));
    }

    /** @return iterable<string, array{string}> */
    public static function badFilters(): iterable
    {
        yield 'parameters without filter' => ['/DecodeParms <<>>'];
        yield 'filter number' => ['/Filter 42'];
        yield 'bad array name' => ['/Filter [42]'];
        yield 'missing parameters' => ['/Filter [/FlateDecode] /DecodeParms []'];
        yield 'parameters shape' => ['/Filter /FlateDecode /DecodeParms []'];
        yield 'parameters item' => ['/Filter [/FlateDecode] /DecodeParms [42]'];
        yield 'unresolved crypt' => ['/Filter /Crypt /DecodeParms << /Name /Secure >>'];
        yield 'late crypt' => ['/Filter [/FlateDecode /Crypt]'];
        yield 'repeated crypt' => ['/Filter [/Crypt /Crypt]'];
    }

    public function testExternalStreamsRequireAnExplicitBridgeCapabilityEvenIfReaderHasOne(): void
    {
        $provider = new ExternalSourceProbe('external');
        $source = (new ImportSource())->file([3 => (new ImportSource())->stream('ignored', '/F (payload)')]);
        $document = (new DocumentReader(externalStreams: $provider))->read($source);
        try {
            (new ImportSession($document, StreamImportMode::PreserveEncoded))->import(new ObjectReference($this->id(3)));
            self::fail('The import requires its own explicit external capability.');
        } catch (UnsupportedPdfFeature) {
            self::assertCount(0, $provider->requests);
        }
    }

    public function testExternalBytesAreAcquiredOnceAndEmbeddedWithTheirOwnFilters(): void
    {
        foreach ([StreamImportMode::PreserveEncoded, StreamImportMode::Decode] as $mode) {
            $provider = new ExternalSourceProbe('68656c6c6f>');
            $document = $this->document('ignored', '/F (payload) /FFilter /ASCIIHexDecode /Filter /UnknownLocal');
            $graph = (new ImportSession($document, $mode, externalStreams: $provider))->import($document->catalog()->get('Data'));
            $native = $graph->root()->target();
            self::assertSame($mode === StreamImportMode::Decode ? 'hello' : '68656c6c6f>', $native->data);
            self::assertCount(1, $provider->requests);
            self::assertNull($native->dictionary()->get('F'));
            self::assertNull($native->dictionary()->get('FFilter'));
        }
    }

    public function testExternalRequestLimitIsCumulativeAcrossRoots(): void
    {
        $fixture = new ImportSource();
        $document = (new DocumentReader())->read($fixture->file([3 => $fixture->stream('', '/F (one)'), 4 => $fixture->stream('', '/F (two)')]));
        $provider = new ExternalSourceProbe('data');
        try {
            (new ImportSession($document, StreamImportMode::PreserveEncoded, new ImportLimits(maxExternalRequests: 1), $provider))->import(new ObjectReference($this->id(3)), new ObjectReference($this->id(4)));
            self::fail('A second external acquisition must fail.');
        } catch (ParseException) {
            self::assertCount(1, $provider->requests);
        }
    }

    public function testEncodedAndDecodedByteBudgetsAndFilterCountAreEnforced(): void
    {
        foreach ([
            [$this->document('12345'), StreamImportMode::PreserveEncoded, new ImportLimits(maxStreamBytes: 4)],
            [$this->document(gzcompress(str_repeat('a', 1000)), '/Filter /FlateDecode'), StreamImportMode::Decode, new ImportLimits(maxStreamBytes: 100)],
            [$this->document('>', '/Filter [/ASCIIHexDecode /FlateDecode]'), StreamImportMode::PreserveEncoded, new ImportLimits(maxFilters: 1)],
        ] as [$document, $mode, $limits]) {
            try {
                (new ImportSession($document, $mode, $limits))->import($document->catalog()->get('Data'));
                self::fail('A stream budget must fail.');
            } catch (ImportException|ParseException) {
                self::assertTrue(true);
            }
        }
    }

    #[DataProvider('encryptedSources')]
    public function testAuthenticatedStreamsPreserveEncodedPlaintextAndOriginalCiphertext(string $file, bool $legacy): void
    {
        $source = new FileBytes(file_get_contents(__DIR__ . '/Fixtures/encryption/' . $file));
        $password = $legacy ? new PdfDocEncodedPassword(new HexadecimalString('owner')) : new PreparedPassword('owner');
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication($password)))->read($source);
        $streams = [];
        foreach ($document->crossReferences()->entries() as $number => $entry) {
            if ($entry->kind !== \PDF\Reading\CrossReference\EntryKind::Uncompressed || $number === 0) {
                continue;
            }
            $value = $document->object($this->id($number));
            if ($value instanceof EncodedStream && $value->encodedBytes() !== $value->originalEncodedBytes()) {
                $streams[] = $value;
            }
        }
        self::assertNotEmpty($streams);
        foreach ($streams as $stream) {
            $ciphertext = $stream->originalEncodedBytes();
            $native = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($stream)->root();
            if ($stream->dictionary()->get('Type')?->value !== 'Metadata') {
                self::assertSame($stream->encodedBytes(), $native->data);
                self::assertSame($stream->encodedBytes(), $native->bytes(new SerializationContext()));
            }
            self::assertSame($ciphertext, $stream->originalEncodedBytes());
        }
        $pages = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->catalog()->get('Pages'))->root();
        $catalog = new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), $pages));
        $copy = (new DocumentReader())->read(new FileBytes((new FileStructure(new FileHeader('1.7'), new FileBody(), new IndirectReference($catalog)))->toPdf()));
        self::assertNull($copy->permissions());
        self::assertNull($copy->trailer()->get('Encrypt'));
        self::assertSame($source->bytes, $document->sourceRevision()->source->bytes);
        $this->expectException(ImportException::class);
        (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->trailer()->get('Encrypt'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function encryptedSources(): iterable
    {
        yield 'RC4' => ['qpdf-r2-40-metadata-encrypted.pdf', true];
        yield 'AES128' => ['qpdf-r4-128-metadata-encrypted.pdf', true];
        yield 'AES256' => ['qpdf-r5-metadata-encrypted.pdf', false];
    }

    /** @param array<int, string> $others */
    private function document(string $bytes, string $entries = '', array $others = []): ReadDocument
    {
        $fixture = new ImportSource();
        return (new DocumentReader())->read($fixture->file($others + [
            1 => '<< /Type /Catalog /Pages 2 0 R /Data 3 0 R ' . (str_contains($entries, '/Type /Metadata') ? '/Metadata 3 0 R' : '') . ' >>',
            3 => $fixture->stream($bytes, $entries),
        ]));
    }

    private function import(ReadDocument $document, StreamImportMode $mode = StreamImportMode::PreserveEncoded): StreamObject
    {
        return (new ImportSession($document, $mode))->import($document->catalog()->get('Data'))->root()->target();
    }

    private function readNative(ReadDocument $document): ReadDocument
    {
        $root = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->trailer()->get('Root'))->root();
        self::assertInstanceOf(IndirectReference::class, $root);
        return (new DocumentReader())->read(new FileBytes((new FileStructure(new FileHeader('2.0'), new FileBody(), $root))->toPdf()));
    }

    private function id(int $number): ObjectIdentifier
    {
        return new ObjectIdentifier(new ObjectNumber($number));
    }
}
