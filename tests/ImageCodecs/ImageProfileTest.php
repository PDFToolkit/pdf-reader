<?php

declare(strict_types=1);

namespace PDF\Reading\Tests\ImageCodecs;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ImageCodecs\AutomaticDecoder;
use PDF\Reading\ImageCodecs\CodecLimit;
use PDF\Reading\ImageCodecs\CodecRequest;
use PDF\Reading\ImageCodecs\CodecUnavailable;
use PDF\Reading\ImageCodecs\GhostscriptDecoder;
use PDF\Reading\ImageCodecs\ImageLimits;
use PDF\Reading\ImageCodecs\Jbig2Header;
use PDF\Reading\ImageCodecs\JpxHeader;
use PDF\Reading\ImageCodecs\NativeProcess;
use PDF\Reading\ImageCodecs\NativeTools;
use PDF\Reading\ImageCodecs\PhpDecoder;
use PDF\Reading\ParseException;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageProfileTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__ . '/Fixtures/manifest.json'), true, flags: JSON_THROW_ON_ERROR)['cases'] as $case) {
            yield $case['file'] => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testNativeProfileDecisionPrecedesDecoding(array $case): void
    {
        $header = $case['filter'] === 'JPXDecode' ? new JpxHeader() : new Jbig2Header();
        if (!$case['native']) {
            $this->expectException(CodecUnavailable::class);
        }
        $description = $header->read($this->request($case));
        self::assertGreaterThan(0, $description->width);
        self::assertGreaterThan(0, $description->height);
    }

    #[DataProvider('cases')]
    public function testPhpPlanesMatchIndependentOrNormativeReferenceExactly(array $case): void
    {
        $this->phpAvailable();
        $image = (new PhpDecoder())->decode($this->request($case));
        if ($case['filter'] === 'JBIG2Decode') {
            self::assertSame($case['samplesSha256'], hash('sha256', $image->components[0]->bytes));
            self::assertSame([$case['width'], $case['height'], 1, false], [$image->width, $image->height, $image->components[0]->precision, $image->components[0]->signed]);
            return;
        }
        $expected = json_decode(file_get_contents(__DIR__ . '/Fixtures/' . $case['expected']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$expected['width'], $expected['height'], $expected['x0'], $expected['y0']], [$image->width, $image->height, $image->originX, $image->originY]);
        self::assertCount(count($expected['components']), $image->components);
        foreach ($image->components as $index => $plane) {
            $reference = $expected['components'][$index];
            self::assertSame(
                [$reference['width'], $reference['height'], $reference['precision'], $reference['signed'], $reference['dx'], $reference['dy'], $reference['x0'], $reference['y0'], $reference['alpha'] ?? 'none'],
                [$plane->width, $plane->height, $plane->precision, $plane->signed, $plane->separationX, $plane->separationY, $plane->originX, $plane->originY, $plane->alpha]
            );
            $samples = [];
            for ($y = 0; $y < $plane->height; ++$y) {
                for ($x = 0; $x < $plane->width; ++$x) {
                    $samples[] = $plane->sample($x, $y);
                }
            }
            self::assertSame($reference['samples'], $samples);
        }
        if ($case['file'] === 'icc-gray.jp2') {
            self::assertSame('a5b596487c1ecb3b99141141427e6584d63252921a2cc36d8a717d7643414aab', hash('sha256', $image->color->iccProfile));
        }
        if ($case['file'] === 'chroma-key.jpx') {
            self::assertSame([1, 2, 3, 4], $image->chromaKey);
            self::assertSame(['none', 'none', 'none', 'none'], array_map(static fn ($p) => $p->alpha, $image->components));
        }
        if (str_starts_with($case['file'], 'alpha-')) {
            self::assertCount(4, $image->channelDefinitions);
            self::assertSame(3, $image->channelDefinitions[3]->outputChannel);
        }
        if ($case['file'] === 'color-alternatives.jpx') {
            self::assertGreaterThan(1, count($image->color->specifications));
            self::assertNotNull($image->color->selectedSpecification);
            self::assertSame('Lab', $image->color->space);
        }
    }

    public function testEncryptedFilteredGlobalsReachBothBackendsUnchanged(): void
    {
        $this->phpAvailable();
        try {
            NativeTools::discover();
        } catch (CodecUnavailable) {
            self::markTestSkipped('Ghostscript and prlimit required.');
        }
        $case = json_decode(file_get_contents(__DIR__ . '/Fixtures/globals-pdf.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ([new GhostscriptDecoder(), new PhpDecoder()] as $backend) {
            foreach (['globals.pdf', 'globals-encrypted.pdf'] as $file) {
                $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user')), imageDecoder: $backend))
                    ->read(new FileBytes(file_get_contents(__DIR__ . '/Fixtures/' . $file)));
                $stream = $document->resolve($document->catalog()->get('TestImage'));
                self::assertInstanceOf(EncodedStream::class, $stream);
                self::assertSame($case['samplesSha256'], hash('sha256', $document->streamData($stream)));
                self::assertSame($case['encodedSha256'], hash('sha256', $stream->encodedBytes()));
                if ($file === 'globals-encrypted.pdf') {
                    self::assertNotSame($stream->originalEncodedBytes(), $stream->encodedBytes());
                }
            }
        }
    }

    public function testMissingNativeFilterIsAFeatureFailureEvenWithLargeUnreadInput(): void
    {
        try {
            $tools = NativeTools::discover();
        } catch (CodecUnavailable) {
            self::markTestSkipped('Ghostscript and prlimit required.');
        }
        $request = new CodecRequest('DCTDecode', str_repeat('x', 262144), 1048576, 1024, 8);
        $this->expectException(CodecUnavailable::class);
        (new NativeProcess($tools))->run('(%stderr) (w) file (PDFTOOLKIT_CODEC_UNAVAILABLE) writestring quit', $request->bytes, $request, 1024);
    }

    public function testJpxTileBudgetIsCheckedBeforeNativeExecution(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/precision-8-signed-0.jp2');
        $siz = strpos($bytes, "\xff\x51");
        $bytes = substr_replace($bytes, pack('NN', 1, 1), $siz + 22, 8);
        $this->expectException(CodecLimit::class);
        (new JpxHeader())->read(new CodecRequest('JPXDecode', $bytes, 65536, 65536, 32, new ImageLimits(maxTiles: 1)));
    }

    public function testNativeJbig2CannotSilentlyIgnoreRegionsAfterEndOfPage(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/generic-t0-tp0.jb2');
        $this->expectException(ParseException::class);
        (new Jbig2Header())->read(new CodecRequest('JBIG2Decode', $bytes . pack('NCCCN', 4, 39, 0, 1, 0), 65536, 65536, 32));
    }

    public function testNativeDctUsesPdfDefaultInsteadOfRgbIdentifierHeuristics(): void
    {
        try {
            NativeTools::discover();
        } catch (CodecUnavailable) {
            self::markTestSkipped('Ghostscript and prlimit required.');
        }
        $decoder = new GhostscriptDecoder();
        $reference = file_get_contents(__DIR__ . '/Fixtures/rgb.jpg');
        $renamed = file_get_contents(__DIR__ . '/Fixtures/rgb-component-identifiers.jpg');
        $request = static fn ($bytes, $transform = null) => new CodecRequest('DCTDecode', $bytes, 65536, 65536, 32, colorTransform: $transform);
        $rgb = $decoder->decode($request($reference))->components;
        $actual = $decoder->decode($request($renamed))->components;
        self::assertEquals($rgb, $actual);
        $raw = $decoder->decode($request($renamed, 0))->components;
        self::assertNotEquals($raw, $rgb);
        $adobe = "\xff\xee" . pack('n', 14) . 'Adobe' . pack('nnnC', 100, 0, 0, 0);
        $marked = substr($renamed, 0, 2) . $adobe . substr($renamed, 2);
        self::assertEquals($raw, $decoder->decode($request($marked, 1))->components);
    }

    public function testJbig2InspectionAccountsForItsSegmentIndexBeforeStartingNativeCode(): void
    {
        $globals = '';
        for ($i = 1; $i <= 9000; ++$i) {
            $globals .= pack('NCCCN', $i, 52, 0, 0, 4) . pack('N', 0);
        }
        $page = pack('NCCCN', 9001, 48, 0, 1, 19) . pack('NNNNCn', 8, 1, 0, 0, 0, 0);
        $request = new CodecRequest(
            'JBIG2Decode',
            $page,
            1048576,
            1048576,
            32,
            new ImageLimits(maxMemoryBytes: 1048576),
            globals: $globals
        );
        $this->expectException(CodecLimit::class);
        $this->expectExceptionMessage('inspection state');
        (new Jbig2Header())->read($request);
    }

    public function testNativeInspectionChargesEncodedBytesAndNotOnlyImagePixels(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/rgb.jpg');
        $request = new CodecRequest('DCTDecode', $bytes, 65536, 65536, 32, new ImageLimits(maxWork: 100));
        $this->expectException(CodecLimit::class);
        $this->expectExceptionMessage('inspection and plane work');
        (new \PDF\Reading\ImageCodecs\JpegHeader())->read($request);
    }

    public function testJpxBoxInspectionAndPlaneWorkShareOneBudget(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/precision-1-signed-0.jp2');
        // Enough for either input inspection or output pixels in isolation,
        // but not for their cumulative cost.
        $request = new CodecRequest('JPXDecode', $bytes, 65536, 65536, 32, new ImageLimits(maxWork: 1200));
        $this->expectException(CodecLimit::class);
        (new JpxHeader())->read($request);
    }

    public function testNativeMemoryLimitDuringLibraryLoadingIsNotAMissingCapability(): void
    {
        try {
            $tools = NativeTools::discover();
        } catch (CodecUnavailable) {
            self::markTestSkipped('Ghostscript and prlimit required.');
        }
        $request = new CodecRequest('DCTDecode', '', 1024, 1024, 8, new ImageLimits(nativeMemoryBytes: 1048576));
        $this->expectException(CodecLimit::class);
        (new NativeProcess($tools))->run('quit', '', $request, 1024);
    }

    private function request(array $case): CodecRequest
    {
        return new CodecRequest(
            $case['filter'],
            file_get_contents(__DIR__ . '/Fixtures/' . $case['file']),
            67108864,
            67108864,
            64,
            globals: isset($case['globals']) ? file_get_contents(__DIR__ . '/Fixtures/' . $case['globals']) : null
        );
    }

    private function phpAvailable(): void
    {
        if (!class_exists(\PDFToolkit\Codecs\PhpDecoder::class)) {
            self::markTestSkipped('Install the optional pdf-codecs package to qualify the PHP backend.');
        }
    }
}
