<?php

declare(strict_types=1);

namespace PDF\Reading\Tests\ImageCodecs;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\ImageCodecs\AutomaticDecoder;
use PDF\Reading\ImageCodecs\CodecLimit;
use PDF\Reading\ImageCodecs\CodecRequest;
use PDF\Reading\ImageCodecs\CodecUnavailable;
use PDF\Reading\ImageCodecs\ComponentPlane;
use PDF\Reading\ImageCodecs\DecodedImage;
use PDF\Reading\ImageCodecs\GhostscriptDecoder;
use PDF\Reading\ImageCodecs\ImageLimits;
use PDF\Reading\ImageCodecs\NativeProcess;
use PDF\Reading\ImageCodecs\NativeTools;
use PDF\Reading\ImageCodecs\PhpDecoder;
use PDF\Reading\ObjectReference;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\StreamDecoder;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\TestCase;

final class ImageCodecsTest extends TestCase
{
    public function testNativeFirstAndFallbackOnlyForUnavailableCapability(): void
    {
        $request = new CodecRequest('DCTDecode', 'input', 1024, 1024, 16);
        $native = new RecordingDecoder();
        $php = new RecordingDecoder();
        (new AutomaticDecoder($native, $php))->decode($request);
        self::assertCount(1, $native->requests);
        self::assertCount(0, $php->requests);
        (new AutomaticDecoder(new RecordingDecoder(failure: new CodecUnavailable('missing')), $php))->decode($request);
        self::assertCount(1, $php->requests);
        foreach ([new ParseException('malformed'), new CodecLimit('budget')] as $error) {
            try {
                (new AutomaticDecoder(new RecordingDecoder(failure: $error), $php))->decode($request);
                self::fail('A failed image must not fall through to another decoder.');
            } catch (ParseException $caught) {
                self::assertSame($error, $caught);
                self::assertCount(1, $php->requests);
            }
        }
    }

    public function testFilterChainPreservesEncodedBytesAndResolvesColorTransform(): void
    {
        $codec = new RecordingDecoder();
        $limits = new ReaderLimits();
        $stream = new EncodedStream(new DictionaryType(
            new Entry(new NameType('Filter'), new ArrayType(new NameType('ASCIIHexDecode'), new NameType('DCTDecode'))),
            new Entry(new NameType('DecodeParms'), new ArrayType(new NullType(), new DictionaryType(new Entry(new NameType('ColorTransform'), new IntegerType(0))))),
        ), '0102ff>');
        self::assertSame("\xa5", (new StreamDecoder($limits, imageDecoder: $codec))->decode($stream, new ReadBudget($limits)));
        self::assertSame("\1\2\xff", $codec->requests[0]->bytes);
        self::assertSame(0, $codec->requests[0]->colorTransform);
        self::assertSame('0102ff>', $stream->encodedBytes());
    }

    public function testParsingIsPassiveAndDocumentCacheSharesImageAndBytes(): void
    {
        $codec = new RecordingDecoder();
        $document = (new DocumentReader(imageDecoder: $codec))->read((new FixtureFactory())->pdf('encoded'));
        self::assertCount(0, $codec->requests);
        $stream = $document->resolve($document->catalog()->get('TestImage'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        $image = $document->imageData($stream);
        self::assertSame($image, $document->imageData($stream));
        self::assertSame("\xa5", $document->streamData($stream));
        self::assertSame("\xa5", $document->streamData($stream));
        self::assertCount(1, $codec->requests);
    }

    public function testStructuralStreamsNeverActivateAnImageCodec(): void
    {
        $codec = new RecordingDecoder();
        $decoder = new StreamDecoder(imageDecoder: $codec, allowImageCodecs: false);
        try {
            $decoder->decode($this->stream('DCTDecode', 'input'), new ReadBudget(new ReaderLimits()));
            self::fail('Structural decoding must reject image filters.');
        } catch (UnsupportedPdfFeature) {
            self::assertCount(0, $codec->requests);
        }
    }

    public function testDistinctPlanesRemainAccessibleWhenInterleavingCannotPreserveThem(): void
    {
        $image = new DecodedImage(1, 1, [new ComponentPlane(1, 1, 12, true, "\xff\xf0", alpha: 'associated')]);
        $codec = new RecordingDecoder($image);
        $document = (new DocumentReader(imageDecoder: $codec))->read((new FixtureFactory())->pdf('encoded', 'JPXDecode'));
        $stream = $document->resolve($document->catalog()->get('TestImage'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame(-1, $document->imageData($stream)->components[0]->sample(0, 0));
        try {
            $document->streamData($stream);
            self::fail('A byte-only API must not discard the signed/alpha metadata.');
        } catch (CodecUnavailable) {
            self::assertSame($image, $document->imageData($stream));
            self::assertCount(1, $codec->requests);
        }
    }

    public function testPackedComponentsInterleaveAtBitPrecisionAndPreserveRowPadding(): void
    {
        $image = new DecodedImage(3, 2, [new ComponentPlane(3, 2, 2, false, "\x18\xe4"), new ComponentPlane(3, 2, 2, false, "\xe4\x18")]);
        self::assertSame("\x36\x90\xc9\x60", $image->streamBytes(4, new ImageLimits()));
    }

    public function testFilteredGlobalsUseDocumentResolutionAndTheSharedDecodedBudget(): void
    {
        $codec = new RecordingDecoder();
        $source = (new FixtureFactory())->pdf('image', 'JBIG2Decode', 'globals');
        $document = (new DocumentReader(new ReaderLimits(maxTotalDecodedBytes: 8), imageDecoder: $codec))->read($source);
        $stream = $document->resolve($document->catalog()->get('TestImage'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame("\xa5", $document->streamData($stream));
        self::assertSame('globals', $codec->requests[0]->globals);
        self::assertSame(1, $codec->requests[0]->maximumOutputBytes);
        $this->expectException(CodecLimit::class);
        $small = (new DocumentReader(new ReaderLimits(maxTotalDecodedBytes: 7), imageDecoder: $codec))->read($source);
        $small->streamData($small->resolve($small->catalog()->get('TestImage')));
    }

    public function testStandaloneGlobalsCycleIsRejectedBeforeAnyBackendRuns(): void
    {
        $reference = new ObjectReference(new ObjectIdentifier(new ObjectNumber(1)));
        $stream = $this->stream('JBIG2Decode', 'cycle', new DictionaryType(new Entry(new NameType('JBIG2Globals'), $reference)));
        $resolver = new class ($stream) implements ObjectResolver {
            public function __construct(private readonly EncodedStream $stream)
            {
            }
            public function resolve(BasicType $value): BasicType
            {
                return $value instanceof ObjectReference ? $this->stream : $value;
            }
        };
        $codec = new RecordingDecoder();
        try {
            (new StreamDecoder(imageDecoder: $codec))->decode($stream, new ReadBudget(new ReaderLimits()), $resolver);
            self::fail('A recursive globals reference must fail.');
        } catch (ParseException $error) {
            self::assertStringContainsString('cycle', $error->getMessage());
            self::assertCount(0, $codec->requests);
        }
    }

    public function testNativeJpegKeepsPlanesAndIgnoresInheritedGhostscriptOptions(): void
    {
        $this->nativeTools();
        $bytes = file_get_contents(__DIR__ . '/Fixtures/rgb.jpg');
        putenv('GS_OPTIONS=-dThisMustNeverBeRead=true -sOutputFile=/not-permitted');
        putenv('GS_LIB=/not-permitted');
        try {
            $image = (new GhostscriptDecoder())->decode(new CodecRequest('DCTDecode', $bytes, 65536, 65536, 32));
        } finally {
            putenv('GS_OPTIONS');
            putenv('GS_LIB');
        }
        self::assertSame([37, 29, 3, 'ghostscript'], [$image->width, $image->height, count($image->components), $image->backend]);
        self::assertSame(3219, strlen($image->streamBytes(65536, new ImageLimits())));
    }

    public function testNativeProcessHasAHardElapsedDeadline(): void
    {
        $tools = $this->nativeTools();
        $request = new CodecRequest('DCTDecode', '', 1024, 1024, 8, new ImageLimits(nativeWallMilliseconds: 100));
        $this->expectException(CodecLimit::class);
        (new NativeProcess($tools))->run('{} loop', '', $request, 1024);
    }

    public function testNativeOutputBudgetStopsTheProcess(): void
    {
        $tools = $this->nativeTools();
        $request = new CodecRequest('DCTDecode', '', 1024, 1024, 8);
        $this->expectException(CodecLimit::class);
        (new NativeProcess($tools))->run('(%stdout) (w) file /o exch def {o (abcdefgh) writestring o flushfile} loop', '', $request, 32);
    }

    public function testPhpFallbackDecodesWithoutExecutingNativeTools(): void
    {
        if (!class_exists(\PDFToolkit\Codecs\PhpDecoder::class)) {
            self::markTestSkipped('Install the optional pdf-codecs package to qualify the PHP backend.');
        }
        $native = new RecordingDecoder(failure: new CodecUnavailable('not installed'));
        $bytes = file_get_contents(__DIR__ . '/Fixtures/cmyk-progressive.jpg');
        $image = (new AutomaticDecoder($native, new PhpDecoder()))->decode(new CodecRequest('DCTDecode', $bytes, 65536, 65536, 32));
        self::assertSame([37, 29, 4, 'php'], [$image->width, $image->height, count($image->components), $image->backend]);
        self::assertSame('Unknown', $image->color->space);
    }

    private function nativeTools(): NativeTools
    {
        try {
            return NativeTools::discover();
        } catch (CodecUnavailable) {
            self::markTestSkipped('Ghostscript and prlimit are required for native image checks.');
        }
    }

    private function stream(string $filter, string $bytes, ?DictionaryType $parameters = null): EncodedStream
    {
        $dictionary = new DictionaryType(new Entry(new NameType('Filter'), new NameType($filter)));
        if ($parameters !== null) {
            $dictionary = $dictionary->with(new Entry(new NameType('DecodeParms'), $parameters));
        }
        return new EncodedStream($dictionary, $bytes);
    }
}
