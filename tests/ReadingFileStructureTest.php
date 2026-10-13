<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\ReaderLimits;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingFileStructureTest extends TestCase
{
    public function testZeroWidthTypeDefaultsToUncompressedAndZeroWidthGenerationDefaultsToZero(): void
    {
        $document = $this->read($this->modernFile(widths: [0, 4, 0], index: [1, 2, 9, 1]));
        self::assertInstanceOf(DictionaryType::class, $document->catalog());
        self::assertInstanceOf(DictionaryType::class, $document->object($this->identifier(2)));
        self::assertInstanceOf(NullType::class, $document->object($this->identifier(2, 1)));
    }

    public function testUnknownCrossReferenceTypesResolveToNull(): void
    {
        $document = $this->read($this->modernFile(entries: [5 => [255, 1234, 456]]));
        self::assertInstanceOf(NullType::class, $document->object($this->identifier(5)));
    }

    public function testMissingIndexDefaultsToTheWholeDeclaredSize(): void
    {
        $document = $this->read($this->modernFile(dictionary: ['Index' => null]));
        self::assertInstanceOf(DictionaryType::class, $document->catalog());
        self::assertInstanceOf(EncodedStream::class, $document->object($this->identifier(9)));
    }

    public function testCompressedIntegerAtEndOfItsMemberView(): void
    {
        $body = $this->objectStream('5 0 ', '123');
        $document = $this->read($this->modernFile(objects: [4 => $body], entries: [5 => [2, 4, 0]]));
        $value = $document->object($this->identifier(5));
        self::assertInstanceOf(IntegerType::class, $value);
        self::assertSame(123, $value->integer());
        self::assertSame($value, $document->object($this->identifier(5)));
    }

    public function testCompressedNumericMembersRemainIndependentlyBounded(): void
    {
        $body = $this->objectStream('5 0 6 4 ', "123\n456", count: 2);
        $document = $this->read($this->modernFile(objects: [4 => $body], entries: [5 => [2, 4, 0], 6 => [2, 4, 1]]));
        $first = $document->object($this->identifier(5));
        $second = $document->object($this->identifier(6));
        self::assertInstanceOf(IntegerType::class, $first);
        self::assertInstanceOf(IntegerType::class, $second);
        self::assertSame(123, $first->integer());
        self::assertSame(456, $second->integer());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidCrossReferenceDictionaries(): iterable
    {
        yield 'two field widths' => [['W' => '[1 4]']];
        yield 'negative field width' => [['W' => '[-1 4 2]']];
        yield 'unsupported wide field' => [['W' => '[1 9 2]']];
        yield 'indirect field width' => [['W' => '[1 4 2 0 R]']];
        yield 'all zero fields' => [['W' => '[0 0 0]']];
        yield 'odd Index array' => [['Index' => '[0 10 1]']];
        yield 'overlapping Index ranges' => [['Index' => '[0 5 4 6]']];
        yield 'unsorted Index ranges' => [['Index' => '[9 1 0 9]']];
        yield 'range exceeds Size' => [['Index' => '[0 11]']];
        yield 'negative range length' => [['Index' => '[0 -1]']];
        yield 'indirect Index number' => [['Index' => '[0 10 0 R]']];
        yield 'indirect structural Size' => [['Size' => '2 0 R']];
        yield 'indirect stream Length' => [['Length' => '2 0 R']];
        yield 'wrong stream Type' => [['Type' => '/ObjStm']];
    }

    #[DataProvider('invalidCrossReferenceDictionaries')]
    public function testMalformedCrossReferenceStructureFailsBeforeResolution(array $dictionary): void
    {
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(dictionary: $dictionary));
    }

    public function testCrossReferenceStreamRequiresGenerationZero(): void
    {
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(xrefGeneration: 1));
    }

    public function testDecodedCrossReferenceRowsMustMatchTheDeclaredRanges(): void
    {
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(payload: str_repeat("\0", 69)));
    }

    public function testCrossReferenceRowsCannotExceedDecodedCapacity(): void
    {
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(), new ReaderLimits(maxDecodedStreamBytes: 69));
    }

    public function testCrossReferenceFieldsCannotOverflowSignedPhpIntegers(): void
    {
        // Type 1 and an eight-byte offset with its high bit set.
        $payload = "\x01\x80\0\0\0\0\0\0\0\0\0";
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(widths: [1, 8, 2], index: [1, 1], payload: $payload));
    }

    public function testUncompressedOffsetCannotPointOutsideTheSource(): void
    {
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(entries: [5 => [1, 4294967295, 0]]));
    }

    public function testCompressedContainerNumberCannotExceedSize(): void
    {
        $this->expectException(ParseException::class);
        $this->read($this->modernFile(entries: [5 => [2, 10, 0]]));
    }

    public function testCompressedContainerMustBeUncompressed(): void
    {
        $document = $this->read($this->modernFile(entries: [4 => [2, 6, 0], 5 => [2, 4, 0]]));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    public function testCompressedContainerMustHaveGenerationZero(): void
    {
        $document = $this->read($this->modernFile(
            objects: [4 => $this->objectStream('5 0 ', '123')],
            entries: [4 => [1, null, 1], 5 => [2, 4, 0]]
        ));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    public function testCompressedContainerMustBeAStream(): void
    {
        $document = $this->read($this->modernFile(objects: [4 => '<< /Type /ObjStm >>'], entries: [5 => [2, 4, 0]]));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    public function testCompressedOrdinalMustIdentifyTheRequestedObjectNumber(): void
    {
        $document = $this->read($this->modernFile(objects: [4 => $this->objectStream('6 0 ', '123')], entries: [5 => [2, 4, 0]]));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    public function testCompressedOrdinalCannotPointPastTheMemberList(): void
    {
        $document = $this->read($this->modernFile(objects: [4 => $this->objectStream('5 0 ', '123')], entries: [5 => [2, 4, 1]]));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    /** @return iterable<string, array{string, string, int, ?int}> */
    public static function malformedObjectStreams(): iterable
    {
        yield 'reference member' => ['5 0 ', '2 0 R', 1, null];
        yield 'stream member' => ['5 0 ', "<< /Length 0 >>\nstream\n\nendstream", 1, null];
        yield 'two values in member' => ['5 0 ', '123 true', 1, null];
        yield 'duplicate object numbers' => ['5 0 5 4 ', '123 true', 2, null];
        yield 'object zero member' => ['0 0 ', '123', 1, null];
        yield 'object number exceeds limit' => ['100000 0 ', '123', 1, null];
        yield 'non-increasing offsets' => ['5 0 6 0 ', '123 true', 2, null];
        yield 'offset at end' => ['5 3 ', '123', 1, null];
        yield 'missing header pair' => ['5 0 ', '123', 2, null];
        yield 'extra header tokens' => ['5 0 6 ', '123', 1, null];
        yield 'First inside header' => ['5 0 ', '123', 1, 2];
        yield 'zero members' => ['5 0 ', '123', 0, null];
        yield 'First beyond stream bytes' => ['5 0 ', '123', 1, 99];
    }

    #[DataProvider('malformedObjectStreams')]
    public function testObjectStreamMembersAndFramingAreValidated(string $header, string $values, int $count, ?int $first): void
    {
        $document = $this->read($this->modernFile(
            objects: [4 => $this->objectStream($header, $values, $count, $first)],
            entries: [5 => [2, 4, 0]]
        ));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    public function testObjectStreamCountIsCheckedBeforeHeaderAllocation(): void
    {
        $document = $this->read($this->modernFile(
            objects: [4 => $this->objectStream('5 0 ', '123', count: 9)],
            entries: [5 => [2, 4, 0]]
        ), new ReaderLimits(maxContainerEntries: 8));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(5));
    }

    public function testIndirectLengthCycleIsRejectedBeforeStreamDataIsRead(): void
    {
        $document = $this->read($this->modernFile(objects: [4 => "<< /Length 5 0 R >>\nstream\na\nendstream",
            5 => "<< /Length 4 0 R >>\nstream\nb\nendstream"]));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(4));
    }

    public function testIndirectLengthLoadingDepthIsBounded(): void
    {
        $objects = [];
        foreach (range(3, 7) as $number) {
            $objects[$number] = '<< /Length ' . ($number + 1) . " 0 R >>\nstream\nx\nendstream";
        }
        $objects[8] = '1';
        $document = $this->read($this->modernFile(objects: $objects), new ReaderLimits(maxDepth: 3));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(3));
    }

    public function testObjectStreamLengthCannotBeStoredInAnotherObjectStream(): void
    {
        $header = '7 0 ';
        $value = '123';
        $length = strlen($header . $value);
        $container = '<< /Type /ObjStm /N 1 /First ' . strlen($header) . " /Length 5 0 R >>\nstream\n"
            . $header . $value . "\nendstream";
        $document = $this->read($this->modernFile(
            objects: [4 => $container, 6 => $this->objectStream('5 0 ', (string) $length)],
            entries: [5 => [2, 6, 0], 7 => [2, 4, 0]]
        ));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(7));
    }

    public function testIndirectLengthIntegerCanBeLoadedExplicitlyForOrdinaryStreams(): void
    {
        $document = $this->read($this->modernFile(objects: [4 => "<< /Length 5 0 R >>\nstream\nabc\nendstream", 5 => '3']));
        $stream = $document->object($this->identifier(4));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame('abc', $document->streamData($stream));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedStreamLengths(): iterable
    {
        yield 'negative length' => ['-1'];
        yield 'length exceeds file limit' => ['2147483647'];
        yield 'length has wrong type' => ['(3)'];
        yield 'null length' => ['null'];
        yield 'length numeric overflow' => ['99999999999999999999999999'];
        yield 'length ends early' => ['2'];
        yield 'length consumes endstream marker' => ['5'];
    }

    #[DataProvider('malformedStreamLengths')]
    public function testMalformedStreamLengthFailsWithoutSearchingForAnotherEndMarker(string $length): void
    {
        $document = $this->read($this->modernFile(objects: [4 => '<< /Length ' . $length . ">>\nstream\nabc\nendstream"]));
        $this->expectException(ParseException::class);
        $document->object($this->identifier(4));
    }

    private function read(string $bytes, ?ReaderLimits $limits = null): ReadDocument
    {
        return (new DocumentReader($limits ?? new ReaderLimits()))->read(new FileBytes($bytes));
    }

    private function identifier(int $number, int $generation = 0): ObjectIdentifier
    {
        return new ObjectIdentifier(new ObjectNumber($number), new GenerationNumber($generation));
    }

    private function objectStream(string $header, string $values, int $count = 1, ?int $first = null): string
    {
        $bytes = $header . $values;
        return '<< /Type /ObjStm /N ' . $count . ' /First ' . ($first ?? strlen($header)) . ' /Length ' . strlen($bytes)
            . ">>\nstream\n" . $bytes . "\nendstream";
    }

    /**
     * Constructs byte fixtures independently of the production PDF writer.
     * @param array<int, string> $objects
     * @param array<int, array{int, ?int, ?int}> $entries Explicit overrides; null retains the calculated field.
     * @param list<int> $widths
     * @param ?list<int> $index
     * @param array<string, ?string> $dictionary A null value omits an optional entry.
     */
    private function modernFile(
        array $objects = [],
        array $entries = [],
        array $widths = [1, 4, 2],
        ?array $index = null,
        ?string $payload = null,
        array $dictionary = [],
        int $xrefGeneration = 0,
    ): string {
        $objects += [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [] /Count 0 >>'];
        ksort($objects);
        $file = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($file);
            $file .= $number . " 0 obj\n" . $value . "\nendobj\n";
        }
        $offsets[9] = strlen($file);
        $index ??= [0, 10];
        if ($payload === null) {
            $payload = '';
            for ($range = 0; $range < count($index); $range += 2) {
                for ($number = $index[$range]; $number < $index[$range] + $index[$range + 1]; ++$number) {
                    $defaults = isset($offsets[$number]) ? [1, $offsets[$number], 0] : [0, 0, $number === 0 ? 65535 : 0];
                    $override = $entries[$number] ?? $defaults;
                    foreach ($widths as $field => $width) {
                        $value = $override[$field] ?? $defaults[$field];
                        $encoded = '';
                        for ($byte = 0; $byte < $width; ++$byte) {
                            $encoded = chr($value & 255) . $encoded;
                            $value = intdiv($value, 256);
                        }
                        $payload .= $encoded;
                    }
                }
            }
        }
        $dictionary += ['Type' => '/XRef', 'Size' => '10', 'W' => '[' . implode(' ', $widths) . ']',
            'Index' => '[' . implode(' ', $index) . ']', 'Root' => '1 0 R', 'Length' => (string) strlen($payload)];
        $pairs = [];
        foreach ($dictionary as $key => $value) {
            if ($value !== null) {
                $pairs[] = '/' . $key . ' ' . $value;
            }
        }
        $file .= '9 ' . $xrefGeneration . " obj\n<< " . implode(' ', $pairs) . " >>\nstream\n" . $payload
            . "\nendstream\nendobj\nstartxref\n" . $offsets[9] . "\n%%EOF\n";
        return $file;
    }
}
