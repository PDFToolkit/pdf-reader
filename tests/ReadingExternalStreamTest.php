<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\ExternalStreamRequest;
use PDF\Reading\FileBytes;
use PDF\Reading\ObjectReference;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\StreamDecoder;
use PDF\Reading\SyntaxParser;
use PDF\Reading\Tests\Support\ExternalSourceProbe;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingExternalStreamTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function inputs(): iterable
    {
        yield 'unfiltered ignores internal bytes and filters' => ['/F (payload) /Filter /NotAFilter /DecodeParms 5', 'hello', 'hello'];
        yield 'dictionary file with external filter' => ['/F << /Type /Filespec /F (payload) >> /FFilter /ASCIIHexDecode', '68656c6c6f>', 'hello'];
        yield 'chain' => ['/F (payload) /FFilter [/ASCIIHexDecode /FlateDecode] /FDecodeParms [null null]', bin2hex(gzcompress('hello')) . '>', 'hello'];
        yield 'empty chain' => ['/F (payload) /FFilter [] /FDecodeParms []', "a\0b", "a\0b"];
        yield 'external predictor' => ['/F (payload) /FFilter /FlateDecode /FDecodeParms << /Predictor 12 /Columns 3 >>', gzcompress("\2abc\2\1\1\1"), 'abcbcd'];
        yield 'explicit null means local' => ['/F null /FFilter null /FDecodeParms null', 'unused', 'local'];
    }

    #[DataProvider('inputs')]
    public function testExternalFiltersApplyOnlyToExplicitlySuppliedBytes(string $fields, string $external, string $expected): void
    {
        $source = new ExternalSourceProbe($external);
        $limits = new ReaderLimits();
        $stream = $this->stream($fields);
        self::assertSame($expected, (new StreamDecoder($limits, $source))->decode($stream, new ReadBudget($limits)));
        self::assertSame('local', $stream->encodedBytes());
        self::assertSame('local', $stream->originalEncodedBytes());
        self::assertCount(str_contains($fields, '/F null') ? 0 : 1, $source->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'invalid file type' => ['/F 42'];
        yield 'external filter without file' => ['/FFilter /FlateDecode'];
        yield 'parameters without file' => ['/FDecodeParms << >>'];
        yield 'parameters without filter' => ['/F (payload) /FDecodeParms << >>'];
        yield 'filter shape' => ['/F (payload) /FFilter /ASCIIHexDecode /FDecodeParms []'];
        yield 'array length' => ['/F (payload) /FFilter [/ASCIIHexDecode] /FDecodeParms []'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidExternalContractsFail(string $fields): void
    {
        $this->expectException(ParseException::class);
        (new StreamDecoder(externalStreams: new ExternalSourceProbe('>')))->decode($this->stream($fields), new ReadBudget(new ReaderLimits()));
    }

    public function testMissingCapabilityRejectsExternalAcquisition(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        (new StreamDecoder())->decode($this->stream('/F (https://invalid.example/payload)'), new ReadBudget(new ReaderLimits()));
    }

    public function testAcquisitionCountIsSharedAndCheckedBeforeCallingTheSource(): void
    {
        $limits = new ReaderLimits(maxExternalStreamRequests: 1);
        $budget = new ReadBudget($limits);
        $source = new ExternalSourceProbe('hello');
        $decoder = new StreamDecoder($limits, $source);
        $decoder->decode($this->stream('/F (one)'), $budget);
        try {
            $decoder->decode($this->stream('/F (two)'), $budget);
            self::fail('Second acquisition must exceed the shared limit.');
        } catch (ParseException $error) {
            self::assertStringContainsString('acquisition limit', $error->getMessage());
        }
        self::assertCount(1, $source->requests);
    }

    public function testProviderCannotExceedTheRequestedByteLimit(): void
    {
        $limits = new ReaderLimits(maxExternalStreamBytes: 4);
        $source = new ExternalSourceProbe('hello');
        try {
            (new StreamDecoder($limits, $source))->decode($this->stream('/F (payload)'), new ReadBudget($limits));
            self::fail('Oversized source must be rejected.');
        } catch (ParseException $error) {
            self::assertStringContainsString('acquisition limit', $error->getMessage());
        }
        self::assertSame(4, $source->requests[0]->maximumBytes);
    }

    public function testExternalAcquisitionSharesRetentionAndDecompressionBudgets(): void
    {
        foreach ([new ReaderLimits(maxRetainedBytes: 3), new ReaderLimits(maxTotalDecodedBytes: 3), new ReaderLimits(maxDecodedStreamBytes: 3)] as $limits) {
            try {
                (new StreamDecoder($limits, new ExternalSourceProbe('hello')))->decode($this->stream('/F (payload)'), new ReadBudget($limits));
                self::fail('Shared byte ceiling must be enforced.');
            } catch (ParseException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFileSpecificationFieldsUseTheOwningResolver(): void
    {
        $fields = $this->dictionary('/F 99 0 R /UF null /FS /URL');
        $resolver = new class () implements ObjectResolver {
            public function resolve(BasicType $value): BasicType
            {
                return $value instanceof ObjectReference ? new LiteralString('payload') : $value;
            }
        };
        $request = new ExternalStreamRequest($fields, 12, $resolver);
        self::assertSame('payload', $request->field('F')->value);
        self::assertNull($request->field('UF'));
        self::assertNull($request->field('Missing'));
        self::assertInstanceOf(NameType::class, $request->field('FS'));
        $this->expectException(UnsupportedPdfFeature::class);
        (new ExternalStreamRequest($fields, 12))->field('F');
    }

    public function testDocumentAcquisitionIsLazyCachedAndSeparateFromHistory(): void
    {
        $source = new ExternalSourceProbe('68656c6c6f>');
        $reader = new DocumentReader(externalStreams: $source);
        $document = $reader->read($this->pdf());
        $reader->readRevisionHistory($this->pdf());
        self::assertCount(0, $source->requests);
        $stream = $document->resolve($document->catalog()->get('Payload'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame('hello', $document->streamData($stream));
        self::assertSame('hello', $document->streamData($stream));
        self::assertCount(1, $source->requests);
        self::assertSame('payload', $source->requests[0]->field('F')->value);
    }

    public function testStrengtheningIntegrityDoesNotAcquireExternalContent(): void
    {
        $source = new ExternalSourceProbe('>');
        $reader = (new DocumentReader(externalStreams: $source))->requiringSourceIntegrity(SourceIntegrityPolicy::RequirePdfMac);
        try {
            $reader->read($this->pdf());
            self::fail('The unauthenticated source has no PDF MAC.');
        } catch (ParseException $error) {
            self::assertStringContainsString('requires a verified PDF MAC', $error->getMessage());
        }
        self::assertCount(0, $source->requests);
    }

    private function stream(string $fields): EncodedStream
    {
        return new EncodedStream($this->dictionary($fields), 'local');
    }

    private function dictionary(string $fields): DictionaryType
    {
        $value = (new SyntaxParser(new ByteCursor('<< ' . $fields . ' >>')))->read();
        self::assertInstanceOf(DictionaryType::class, $value);
        return $value;
    }

    private function pdf(): FileBytes
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R /Payload 3 0 R >>',
            '<< /Type /Pages /Kids [] /Count 0 >>',
            "<< /Length 5 /F 4 0 R /FFilter 6 0 R >>\nstream\nlocal\nendstream",
            '<< /Type /Filespec /F 5 0 R >>', '(payload)', '/ASCIIHexDecode',
        ];
        $bytes = "%PDF-2.0\n";
        $offsets = [];
        foreach ($objects as $index => $value) {
            $offsets[] = strlen($bytes);
            $bytes .= ($index + 1) . " 0 obj\n" . $value . "\nendobj\n";
        }
        $offset = strlen($bytes);
        $bytes .= "xref\n0 7\n0000000000 65535 f \n";
        foreach ($offsets as $position) {
            $bytes .= sprintf("%010d 00000 n \n", $position);
        }
        return new FileBytes($bytes . "trailer << /Size 7 /Root 1 0 R >>\nstartxref\n" . $offset . "\n%%EOF\n");
    }
}
