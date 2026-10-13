<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\ReaderLimits;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingPagesTest extends TestCase
{
    public static function externalFixtures(): iterable
    {
        yield 'classic xref' => ['classic.pdf'];
        yield 'compressed dictionaries' => ['object-streams.pdf'];
    }

    #[DataProvider('externalFixtures')]
    public function testIndependentNestedPageTrees(string $name): void
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/Fixtures/page-tree/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = $manifest['fixtures'][$name];
        $document = $this->fixture($name);
        $tree = (new PageTreeReader($document, new PageTreeLimits(maxDepth: 4, maxNodes: 6, maxPages: 3)))->read();
        self::assertCount(3, $tree);
        self::assertSame($expected['page_tree']['root']['number'], $tree->root->identifier->objectNumber->value);
        self::assertSame($document, $tree->document);
        foreach ($tree as $index => $page) {
            $information = $expected['pages'][$index];
            self::assertSame($tree->page($index), $page);
            self::assertSame($document, $page->document);
            self::assertSame($information['identifier']['number'], $page->reference->identifier->objectNumber->value);
            self::assertSame($information['identifier']['generation'], $page->reference->identifier->generationNumber->value);
            self::assertSame($document->resolve($page->reference), $page->dictionary);
            self::assertSame($information['media_box'], array_map(static fn ($number) => $number->value, $page->attributes->mediaBox->items));
            self::assertSame($information['crop_box'], array_map(static fn ($number) => $number->value, $page->attributes->cropBox->items));
            self::assertSame($information['rotation'], $page->attributes->rotate->integer());
            self::assertSame((($information['rotation'] % 360) + 360) % 360, $page->attributes->normalizedRotation());
            $fonts = $page->attributes->resources->get('Font');
            $actualFonts = [];
            if ($fonts !== null) {
                $fonts = $document->resolve($fonts);
                self::assertInstanceOf(DictionaryType::class, $fonts);
                foreach ($fonts->entries() as $entry) {
                    $font = $document->resolve($entry->value);
                    self::assertInstanceOf(DictionaryType::class, $font);
                    $fontName = $font->get('BaseFont');
                    self::assertInstanceOf(NameType::class, $fontName);
                    $actualFonts[$entry->key->value] = $fontName->value;
                }
            }
            self::assertSame($information['resource_fonts'], $actualFonts);
            $contents = (new PageContentsReader($document))->read($page);
            self::assertSame($page, $contents->page);
            self::assertCount(count($information['contents']), $contents);
            foreach ($contents as $ordinal => $stream) {
                self::assertSame(hex2bin($information['contents'][$ordinal]['encoded_hex']), $stream->encodedBytes());
                self::assertSame(hex2bin($information['contents'][$ordinal]['decoded_hex']), $document->streamData($stream));
            }
        }
        // Inspection must not flatten inheritance into the source dictionaries.
        self::assertNull($tree->page(0)->dictionary->get('MediaBox'));
        self::assertNull($tree->page(0)->dictionary->get('Resources'));
        self::assertCount(0, $tree->page(1)->attributes->resources);
    }

    public static function nullResources(): iterable
    {
        yield 'direct null' => ['null'];
        yield 'indirect null' => ['9 0 R'];
        yield 'wrong generation' => ['7 1 R'];
    }

    #[DataProvider('nullResources')]
    public function testNullAndMissingReferencedValuesContinueInheritance(string $resources): void
    {
        $document = $this->document([
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /Resources << /Marker /Root >> /MediaBox [0 0 400 600] /CropBox [10 20 390 580] /Rotate -90 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources ' . $resources . ' /MediaBox null /CropBox 99 0 R /Rotate 8 0 R >>',
            7 => '<< /Marker /WrongGeneration >>',
            8 => 'null',
            9 => 'null',
        ]);
        $page = (new PageTreeReader($document))->read()->page(0);
        self::assertSame('Root', $page->attributes->resources->get('Marker')->value);
        self::assertSame(400, $page->attributes->mediaBox->upperRightX->value);
        self::assertSame(10, $page->attributes->cropBox->lowerLeftX->value);
        self::assertSame(-90, $page->attributes->rotate->integer());
        self::assertSame(270, $page->attributes->normalizedRotation());
    }

    public function testEmptyLocalResourcesAndZeroRotationOverrideAncestors(): void
    {
        $document = $this->document([
            2 => '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /Resources << /Marker /Root >> /MediaBox [0 0 400 600] /Rotate 450 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources <<>> /Rotate 0 >>',
            4 => '<< /Type /Page /Parent 2 0 R >>',
        ]);
        $tree = (new PageTreeReader($document))->read();
        self::assertCount(0, $tree->page(0)->attributes->resources);
        self::assertSame(0, $tree->page(0)->attributes->rotate->integer());
        self::assertSame('Root', $tree->page(1)->attributes->resources->get('Marker')->value);
        self::assertSame(450, $tree->page(1)->attributes->rotate->integer());
        self::assertSame(90, $tree->page(1)->attributes->normalizedRotation());
        self::assertSame($tree->page(0)->attributes->mediaBox, $tree->page(1)->attributes->mediaBox);
    }

    public function testCropDefaultUsesLeafMediaBoxWithoutEarlyAncestorDefaults(): void
    {
        $document = $this->document([3 => '<< /Type /Page /Parent 2 0 R /MediaBox [400 500 -10 -20] >>']);
        $page = (new PageTreeReader($document))->read()->page(0);
        self::assertSame($page->attributes->mediaBox, $page->attributes->cropBox);
        self::assertSame([400, 500, -10, -20], array_map(static fn ($number) => $number->value, $page->attributes->mediaBox->items));
        self::assertSame(0, $page->attributes->rotate->integer());
    }

    public function testIndirectTypesCountsArraysBoxesAndCoordinatesAreResolved(): void
    {
        $document = $this->document([
            2 => '<< /Type 4 0 R /Kids 5 0 R /Count 6 0 R /Resources 7 0 R /MediaBox 8 0 R >>',
            3 => '<< /Type 9 0 R /Parent 2 0 R /Rotate 10 0 R /CropBox [-100 -200 800 900] >>',
            4 => '/Pages', 5 => '[3 0 R]', 6 => '1', 7 => '<< /Opaque 99 0 R >>',
            8 => '[0 11 0 R 400 600]', 9 => '/Page', 10 => '-450', 11 => '0.5',
        ]);
        $page = (new PageTreeReader($document))->read()->page(0);
        self::assertSame(0.5, $page->attributes->mediaBox->lowerLeftY->value);
        self::assertSame([-100, -200, 800, 900], array_map(static fn ($number) => $number->value, $page->attributes->cropBox->items));
        self::assertSame(-450, $page->attributes->rotate->integer());
        self::assertSame(270, $page->attributes->normalizedRotation());
        // Opaque resource members are retained; enumerating pages does not load them.
        self::assertSame(99, $page->attributes->resources->get('Opaque')->identifier->objectNumber->value);
    }

    public function testRootParentNullIsAbsentAndAnEmptyTreeNeedsNoPageDefaults(): void
    {
        $document = $this->document([2 => '<< /Type /Pages /Parent null /Kids [] /Count 0 >>']);
        self::assertCount(0, (new PageTreeReader($document))->read());
    }

    public function testRootParentMissingReferenceIsAlsoAbsent(): void
    {
        $document = $this->document([2 => '<< /Type /Pages /Parent 99 0 R /Kids [] /Count 0 >>']);
        self::assertCount(0, (new PageTreeReader($document))->read());
    }

    public function testParentAndChildGenerationsRemainPartOfTheirIdentity(): void
    {
        $document = $this->document([
            1 => '<< /Type /Catalog /Pages 2 17 R >>',
            2 => '<< /Type /Pages /Kids [3 29 R] /Count 1 /Resources <<>> /MediaBox [0 0 400 600] >>',
            3 => '<< /Type /Page /Parent 2 17 R >>',
        ], generations: [2 => 17, 3 => 29]);
        $tree = (new PageTreeReader($document))->read();
        self::assertSame(17, $tree->root->identifier->generationNumber->value);
        self::assertSame(29, $tree->page(0)->reference->identifier->generationNumber->value);
    }

    public static function malformedTrees(): iterable
    {
        $root = '/Resources <<>> /MediaBox [0 0 400 600]';
        yield 'root is Page' => [[2 => '<< /Type /Page ' . $root . ' >>']];
        yield 'root Parent' => [[2 => '<< /Type /Pages /Parent 1 0 R /Kids [3 0 R] /Count 1 ' . $root . ' >>']];
        yield 'missing root Type' => [[2 => '<< /Kids [3 0 R] /Count 1 ' . $root . ' >>']];
        yield 'missing Kids' => [[2 => '<< /Type /Pages /Count 1 ' . $root . ' >>']];
        yield 'null Kids' => [[2 => '<< /Type /Pages /Kids null /Count 1 ' . $root . ' >>']];
        yield 'nonarray Kids' => [[2 => '<< /Type /Pages /Kids 3 0 R /Count 1 ' . $root . ' >>']];
        yield 'direct Kids dictionary' => [[2 => '<< /Type /Pages /Kids [<< /Type /Page /Parent 2 0 R >>] /Count 1 ' . $root . ' >>']];
        yield 'null Kids member' => [[2 => '<< /Type /Pages /Kids [null] /Count 1 ' . $root . ' >>']];
        yield 'missing child' => [[2 => '<< /Type /Pages /Kids [99 0 R] /Count 1 ' . $root . ' >>']];
        yield 'missing Count' => [[2 => '<< /Type /Pages /Kids [3 0 R] ' . $root . ' >>']];
        yield 'negative Count' => [[2 => '<< /Type /Pages /Kids [3 0 R] /Count -1 ' . $root . ' >>']];
        yield 'real Count' => [[2 => '<< /Type /Pages /Kids [3 0 R] /Count 1.0 ' . $root . ' >>']];
        yield 'boolean Count' => [[2 => '<< /Type /Pages /Kids [3 0 R] /Count true ' . $root . ' >>']];
        yield 'Count too small' => [[2 => '<< /Type /Pages /Kids [3 0 R] /Count 0 ' . $root . ' >>']];
        yield 'Count too large' => [[2 => '<< /Type /Pages /Kids [3 0 R] /Count 2 ' . $root . ' >>']];
        yield 'Count enormous' => [[2 => '<< /Type /Pages /Kids [] /Count ' . PHP_INT_MAX . ' ' . $root . ' >>']];
        yield 'missing Parent' => [[3 => '<< /Type /Page >>']];
        yield 'null Parent' => [[3 => '<< /Type /Page /Parent null >>']];
        yield 'wrong Parent' => [[3 => '<< /Type /Page /Parent 1 0 R >>']];
        yield 'wrong parent generation' => [[3 => '<< /Type /Page /Parent 2 1 R >>']];
        yield 'direct Parent' => [[3 => '<< /Type /Page /Parent << /Type /Pages >> >>']];
        yield 'wrong child generation' => [[2 => '<< /Type /Pages /Kids [3 1 R] /Count 1 ' . $root . ' >>']];
        yield 'missing child Type' => [[3 => '<< /Parent 2 0 R >>']];
        yield 'wrong child Type' => [[3 => '<< /Type /Catalog /Parent 2 0 R >>']];
        yield 'child is scalar' => [[3 => '42']];
        yield 'duplicate page' => [[2 => '<< /Type /Pages /Kids [3 0 R 3 0 R] /Count 2 ' . $root . ' >>']];
        yield 'cycle to root' => [[2 => '<< /Type /Pages /Kids [2 0 R] /Count 0 ' . $root . ' >>']];
        yield 'duplicate subtree' => [[
            2 => '<< /Type /Pages /Kids [4 0 R 4 0 R] /Count 2 ' . $root . ' >>',
            4 => '<< /Type /Pages /Parent 2 0 R /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 4 0 R >>',
        ]];
        yield 'subtree Count too small' => [[
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 ' . $root . ' >>',
            4 => '<< /Type /Pages /Parent 2 0 R /Kids [3 0 R] /Count 0 >>',
            3 => '<< /Type /Page /Parent 4 0 R >>',
        ]];
        yield 'subtree Count too large' => [[
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 ' . $root . ' >>',
            4 => '<< /Type /Pages /Parent 2 0 R /Kids [3 0 R] /Count 2 >>',
            3 => '<< /Type /Page /Parent 4 0 R >>',
        ]];
    }

    #[DataProvider('malformedTrees')]
    public function testMalformedPageStructureIsRejectedWithoutPartialResult(array $objects): void
    {
        $document = $this->document($objects);
        $this->expectException(ParseException::class);
        (new PageTreeReader($document))->read();
    }

    public static function malformedAttributes(): iterable
    {
        foreach (['/Resources []', '/Resources /Invalid', '/MediaBox [0 0 100]', '/MediaBox [0 0 100 200 300]', '/MediaBox [0 0 /Bad 200]', '/MediaBox [0 0 99 0 R 200]', '/MediaBox <<>>', '/CropBox true', '/Rotate 45', '/Rotate 90.0', '/Rotate /Bad'] as $attribute) {
            yield $attribute => [$attribute];
        }
    }

    #[DataProvider('malformedAttributes')]
    public function testMalformedEffectiveValuesAreRejected(string $attribute): void
    {
        $document = $this->document([3 => '<< /Type /Page /Parent 2 0 R ' . $attribute . ' >>']);
        $this->expectException(ParseException::class);
        (new PageTreeReader($document))->read();
    }

    public static function missingRequiredAttributes(): iterable
    {
        yield 'MediaBox absent' => ['/Resources <<>>'];
        yield 'Resources absent' => ['/MediaBox [0 0 400 600]'];
        yield 'both null' => ['/Resources null /MediaBox null'];
    }

    #[DataProvider('missingRequiredAttributes')]
    public function testMissingRequiredInheritedValuesAreNotInvented(string $attributes): void
    {
        $document = $this->document([2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 ' . $attributes . ' >>']);
        $this->expectException(ParseException::class);
        (new PageTreeReader($document))->read();
    }

    public static function treeLimits(): iterable
    {
        yield 'depth' => [new PageTreeLimits(maxDepth: 3)];
        yield 'nodes' => [new PageTreeLimits(maxNodes: 5)];
        yield 'pages' => [new PageTreeLimits(maxPages: 2)];
    }

    #[DataProvider('treeLimits')]
    public function testTreeCeilingsApplyAcrossIndirectReferences(PageTreeLimits $limits): void
    {
        $document = $this->fixture('classic.pdf');
        $this->expectException(ParseException::class);
        (new PageTreeReader($document, $limits))->read();
    }

    public function testActualPageLimitDoesNotTrustADishonestCount(): void
    {
        $document = $this->document([
            2 => '<< /Type /Pages /Kids [3 0 R 4 0 R 5 0 R 6 0 R] /Count 3 /Resources <<>> /MediaBox [0 0 400 600] >>',
            4 => '<< /Type /Page /Parent 2 0 R >>', 5 => '<< /Type /Page /Parent 2 0 R >>', 6 => '<< /Type /Page /Parent 2 0 R >>',
        ]);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('page limit');
        (new PageTreeReader($document, new PageTreeLimits(maxPages: 3)))->read();
    }

    public function testDeepTreesUseAnExplicitStackAndReadersHaveNoSharedTraversalState(): void
    {
        $objects = [2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /Resources <<>> /MediaBox [0 0 400 600] >>'];
        for ($number = 3; $number < 1026; ++$number) {
            $objects[$number] = sprintf('<< /Type /Pages /Parent %d 0 R /Kids [%d 0 R] /Count 1 >>', $number - 1, $number + 1);
        }
        $objects[1026] = '<< /Type /Page /Parent 1025 0 R >>';
        $document = $this->document($objects);
        $reader = new PageTreeReader($document, new PageTreeLimits(maxDepth: 1025, maxNodes: 1025));
        foreach ([$reader->read(), $reader->read()] as $tree) {
            self::assertCount(1, $tree);
            self::assertSame(1026, $tree->page(0)->reference->identifier->objectNumber->value);
        }
        self::assertCount(3, (new PageTreeReader($this->fixture('classic.pdf')))->read());
    }

    public static function invalidLimits(): iterable
    {
        yield 'depth' => [0, 1, 1];
        yield 'nodes' => [1, 0, 1];
        yield 'pages' => [1, 1, -1];
    }

    #[DataProvider('invalidLimits')]
    public function testNonpositiveTreeLimitsAreRejected(int $depth, int $nodes, int $pages): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageTreeLimits($depth, $nodes, $pages);
    }

    public function testNonpositiveContentLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageContentLimits(0);
    }

    public static function missingContents(): iterable
    {
        yield 'absent' => [''];
        yield 'null' => ['/Contents null'];
        yield 'missing reference' => ['/Contents 99 0 R'];
        yield 'empty array' => ['/Contents []'];
    }

    #[DataProvider('missingContents')]
    public function testEmptyPageContentsAreNeverInherited(string $contents): void
    {
        $document = $this->document([
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /Resources <<>> /MediaBox [0 0 400 600] /Contents 4 0 R >>',
            3 => '<< /Type /Page /Parent 2 0 R ' . $contents . ' >>',
            4 => $this->stream('ancestor content'),
        ]);
        $page = (new PageTreeReader($document))->read()->page(0);
        self::assertCount(0, (new PageContentsReader($document))->read($page));
    }

    public function testContentsOrderAndRepeatedStreamsArePreservedWithoutJoiningBytes(): void
    {
        $document = $this->document([
            3 => '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
            4 => $this->stream('q'), 5 => '[4 0 R 6 0 R 4 0 R]', 6 => $this->stream('Q'),
        ], limits: new ReaderLimits(maxTotalDecodedBytes: 2));
        $page = (new PageTreeReader($document))->read()->page(0);
        $contents = (new PageContentsReader($document, new PageContentLimits(maxStreams: 3)))->read($page);
        self::assertCount(3, $contents);
        self::assertSame($contents->streams[0], $contents->streams[2]);
        self::assertSame(['q', 'Q', 'q'], array_map($document->streamData(...), $contents->streams));
    }

    public function testUnknownContentCodecDoesNotPreventInspection(): void
    {
        $document = $this->document([
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => $this->stream('encoded bytes', '/Filter /UnknownCodec'),
        ]);
        $page = (new PageTreeReader($document))->read()->page(0);
        $contents = (new PageContentsReader($document))->read($page);
        self::assertCount(1, $contents);
        self::assertSame('encoded bytes', $contents->streams[0]->encodedBytes());
        $this->expectException(UnsupportedPdfFeature::class);
        $document->streamData($contents->streams[0]);
    }

    public function testInspectionDoesNotDecodeAndExplicitDecodingKeepsDocumentBudgets(): void
    {
        $document = $this->document([
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => $this->stream('123456789'),
        ], limits: new ReaderLimits(maxDecodedStreamBytes: 8));
        $page = (new PageTreeReader($document))->read()->page(0);
        $contents = (new PageContentsReader($document))->read($page);
        self::assertSame('123456789', $contents->streams[0]->encodedBytes());
        $this->expectException(ParseException::class);
        $document->streamData($contents->streams[0]);
    }

    public function testContentStreamLimitIsCheckedBeforeResolvingAnyMembers(): void
    {
        $document = $this->document([
            3 => '<< /Type /Page /Parent 2 0 R /Contents [4 0 R 5 0 R] >>',
            4 => '<< /Length 999999 >> stream bad', 5 => 'false',
        ]);
        $page = (new PageTreeReader($document))->read()->page(0);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('stream-count limit');
        (new PageContentsReader($document, new PageContentLimits(maxStreams: 1)))->read($page);
    }

    public static function malformedContents(): iterable
    {
        yield 'number' => ['/Contents 42'];
        yield 'dictionary' => ['/Contents <<>>'];
        yield 'null member' => ['/Contents [null]'];
        yield 'missing member' => ['/Contents [99 0 R]'];
        yield 'number member' => ['/Contents [42]'];
        yield 'array member' => ['/Contents [[]]'];
    }

    #[DataProvider('malformedContents')]
    public function testMalformedContentsFailOnlyWhenInspectionIsRequested(string $contents): void
    {
        $document = $this->document([3 => '<< /Type /Page /Parent 2 0 R ' . $contents . ' >>']);
        $page = (new PageTreeReader($document))->read()->page(0);
        $this->expectException(ParseException::class);
        (new PageContentsReader($document))->read($page);
    }

    public function testAReadPageCannotBeResolvedAgainstAnotherDocument(): void
    {
        $first = $this->document([3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>', 4 => $this->stream('first')]);
        $second = $this->document([3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>', 4 => $this->stream('second')]);
        $page = (new PageTreeReader($first))->read()->page(0);
        $this->expectException(\InvalidArgumentException::class);
        (new PageContentsReader($second))->read($page);
    }

    public static function invalidIndices(): iterable
    {
        yield 'negative' => [-1];
        yield 'end' => [3];
    }

    #[DataProvider('invalidIndices')]
    public function testPageIndicesAreExplicitlyZeroBased(int $index): void
    {
        $tree = (new PageTreeReader($this->fixture('classic.pdf')))->read();
        $this->expectException(\OutOfRangeException::class);
        $tree->page($index);
    }

    private function fixture(string $name): ReadDocument
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/page-tree/' . $name);
        self::assertIsString($bytes);
        return (new DocumentReader())->read(new FileBytes($bytes));
    }

    /** Build adversarial classic-xref inputs without invoking the production writer.
     * @param array<int, string> $overrides
     * @param array<int, int> $generations
     */
    private function document(array $overrides = [], array $generations = [], ?ReaderLimits $limits = null): ReadDocument
    {
        $objects = array_replace([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /Resources <<>> /MediaBox [0 0 400 600] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>',
        ], $overrides);
        ksort($objects);
        $bytes = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($bytes);
            $bytes .= sprintf("%d %d obj\n%s\nendobj\n", $number, $generations[$number] ?? 0, $object);
        }
        $start = strlen($bytes);
        $size = max(array_keys($objects)) + 1;
        $bytes .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; ++$number) {
            $bytes .= isset($offsets[$number])
                ? sprintf("%010d %05d n \n", $offsets[$number], $generations[$number] ?? 0)
                : "0000000000 00000 f \n";
        }
        $bytes .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
        return (new DocumentReader($limits ?? new ReaderLimits()))->read(new FileBytes($bytes));
    }

    private function stream(string $bytes, string $entries = ''): string
    {
        return '<< /Length ' . strlen($bytes) . ' ' . $entries . ">>\nstream\n" . $bytes . "\nendstream";
    }
}
