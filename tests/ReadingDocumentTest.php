<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReaderLimits;
use PDF\Reading\ReadDocument;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingDocumentTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function externalFixtures(): iterable
    {
        foreach (['classic', 'object-streams', 'incremental', 'deleted-object', 'reused-generation'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('externalFixtures')]
    public function testExternalGraphsAndBinaryStreams(string $name): void
    {
        $document = $this->fixture($name);
        $catalog = $document->catalog();
        self::assertInstanceOf(NameType::class, $catalog->get('Type'));
        self::assertSame('Catalog', $catalog->get('Type')->value);
        $pages = $document->resolve($catalog->get('Pages'));
        self::assertInstanceOf(DictionaryType::class, $pages);
        self::assertInstanceOf(IntegerType::class, $pages->get('Count'));
        self::assertSame(1, $pages->get('Count')->integer());
        $data = $document->resolve($catalog->get('FixtureData'));
        self::assertInstanceOf(EncodedStream::class, $data);
        $bytes = "\x00\xff\nendstream\nendobj\nxref\nstartxref\n%%EOF\n" . implode('', array_map(chr(...), range(0, 255)));
        self::assertSame($bytes, $document->streamData($data));
        self::assertSame($bytes, $document->streamData($data));
        self::assertInstanceOf(StringType::class, $catalog->get('FixtureBinary'));
        self::assertSame(implode('', array_map(chr(...), range(0, 255))), $catalog->get('FixtureBinary')->value);
        self::assertInstanceOf(NameType::class, $catalog->get('FixtureName'));
        self::assertSame('A name/#()', $catalog->get('FixtureName')->value);
    }

    public function testIndirectLengthDoesNotSearchEmbeddedTerminators(): void
    {
        $document = $this->fixture('classic');
        $stream = $document->resolve($document->catalog()->get('FixtureData'));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertInstanceOf(ObjectReference::class, $stream->dictionary()->get('Length'));
        self::assertSame(297, strlen($document->streamData($stream)));
    }

    public function testLatestRevisionWinsWithoutResurrectingFreeObjects(): void
    {
        $document = $this->fixture('incremental');
        $information = $document->resolve($document->trailer()->get('Info'));
        self::assertInstanceOf(DictionaryType::class, $information);
        self::assertInstanceOf(StringType::class, $information->get('Title'));
        self::assertSame('Updated revision', $information->get('Title')->value);
        self::assertInstanceOf(NullType::class, $this->fixture('deleted-object')->object(new ObjectIdentifier(new ObjectNumber(9))));
    }

    public function testGenerationAndMissingObjectResolution(): void
    {
        $document = $this->fixture('reused-generation');
        self::assertInstanceOf(NullType::class, $document->object(new ObjectIdentifier(new ObjectNumber(9))));
        self::assertInstanceOf(NullType::class, $document->object(new ObjectIdentifier(new ObjectNumber(999))));
        $current = $document->object(new ObjectIdentifier(new ObjectNumber(9), new GenerationNumber(1)));
        self::assertInstanceOf(StringType::class, $current);
        self::assertSame('reused generation one', $current->value);
    }

    public function testIdentitiesAreScopedToTheirDocument(): void
    {
        $reader = new DocumentReader();
        $left = $reader->read(new FileBytes($this->bytes('classic')));
        $right = $reader->read(new FileBytes($this->bytes('classic')));
        self::assertNotSame($left->catalog(), $right->catalog());
        self::assertSame($left->catalog(), $left->resolve($left->trailer()->get('Root')));
    }

    public function testFileCapacityIsCheckedBeforeParsing(): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxFileBytes: 8)))->read(new FileBytes($this->bytes('classic')));
    }

    public function testRevisionAndCumulativeXrefLimits(): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxRevisions: 1)))->read(new FileBytes($this->bytes('incremental')));
    }

    public function testCrossReferenceCapacityIsCheckedBeforeAllocation(): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxCrossReferenceEntries: 2)))->read(new FileBytes($this->bytes('classic')));
    }

    public function testDecodedStreamCapacity(): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxDecodedStreamBytes: 8)))->read(new FileBytes($this->bytes('object-streams')));
    }

    public function testMalformedFooterCannotSelectAnOlderRevision(): void
    {
        $bytes = $this->bytes('incremental') . "garbage after EOF";
        $this->expectException(ParseException::class);
        (new DocumentReader())->read(new FileBytes($bytes));
    }

    public function testLatestSizeCannotHideObjectsInPreviousRevisions(): void
    {
        $bytes = $this->bytes('incremental');
        $trailer = strrpos($bytes, 'trailer');
        self::assertNotFalse($trailer);
        $tail = preg_replace('/\/Size\s+10\b/', '/Size 2', substr($bytes, $trailer));
        self::assertNotNull($tail);
        $this->expectException(ParseException::class);
        (new DocumentReader())->read(new FileBytes(substr($bytes, 0, $trailer) . $tail));
    }

    public function testEncryptedTrailerIsExplicitlyRejected(): void
    {
        $bytes = $this->bytes('classic');
        $trailer = strrpos($bytes, 'trailer');
        self::assertNotFalse($trailer);
        $bytes = substr_replace($bytes, '/Encrypt 1 0 R ', strpos($bytes, '<<', $trailer) + 2, 0);
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->read(new FileBytes($bytes));
    }

    public function testHybridTrailerIsExplicitlyRejected(): void
    {
        $bytes = $this->bytes('classic');
        $trailer = strrpos($bytes, 'trailer');
        self::assertNotFalse($trailer);
        $bytes = substr_replace($bytes, '/XRefStm 1 ', strpos($bytes, '<<', $trailer) + 2, 0);
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->read(new FileBytes($bytes));
    }

    public function testPrevCyclesAreRejected(): void
    {
        $bytes = $this->bytes('classic');
        preg_match('/startxref\s+(\d+)/', substr($bytes, strrpos($bytes, 'trailer')), $matches);
        $trailer = strrpos($bytes, 'trailer');
        $bytes = substr_replace($bytes, '/Prev ' . $matches[1] . ' ', strpos($bytes, '<<', $trailer) + 2, 0);
        $this->expectException(ParseException::class);
        (new DocumentReader())->read(new FileBytes($bytes));
    }

    public function testObjectOffsetMustMatchItsIdentifier(): void
    {
        $bytes = preg_replace('/0000000015 00000 n/', '0000000016 00000 n', $this->bytes('classic'));
        self::assertNotNull($bytes);
        $document = (new DocumentReader())->read(new FileBytes($bytes));
        $this->expectException(ParseException::class);
        $document->object(new ObjectIdentifier(new ObjectNumber(1)));
    }

    private function fixture(string $name): ReadDocument
    {
        return (new DocumentReader())->read(new FileBytes($this->bytes($name)));
    }

    private function bytes(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf/' . $name . '.pdf');
        self::assertNotFalse($bytes);
        return $bytes;
    }
}
