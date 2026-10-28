<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\FileStructure;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Import\ImportedArray;
use PDF\Reading\Import\ImportedDictionary;
use PDF\Reading\Import\ImportedStream;
use PDF\Reading\Import\ImportException;
use PDF\Reading\Import\ImportLimits;
use PDF\Reading\Import\ImportSession;
use PDF\Reading\Import\StreamImportMode;
use PDF\Reading\ObjectReference;
use PDF\Reading\Tests\Support\ImportSource;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingImportTest extends TestCase
{
    public function testActualPageTreeCyclesAliasesAndStreamSelfReferencesBecomeNative(): void
    {
        $fixture = new ImportSource();
        $source = $fixture->file([
            1 => '<< /Type /Catalog /Pages 2 0 R /First 4 0 R /Alias 5 0 R /Cycle 6 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources <<>> /Contents 4 0 R >>',
            4 => $fixture->stream('q Q', '/Self 4 0 R'),
            5 => '4 0 R',
            6 => '[6 0 R 1 0 R]',
        ]);
        $document = (new DocumentReader())->read($source);
        $root = $document->trailer()->get('Root');
        $graph = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($root);
        $native = $graph->root();
        self::assertInstanceOf(IndirectReference::class, $native);
        $catalog = $native->target();
        self::assertSame($catalog->get('First'), $catalog->get('Alias'));
        self::assertSame($graph->reference($this->id(4)), $graph->reference($this->id(5)));
        self::assertSame($catalog->get('First'), $catalog->get('First')->target()->dictionary()->get('Self'));
        self::assertSame($catalog->get('Cycle'), $catalog->get('Cycle')->target()->items[0]);
        self::assertSame($native, $catalog->get('Cycle')->target()->items[1]);
        self::assertNull($native->identifier);
        $bytes = (new FileStructure(new FileHeader('2.0'), new FileBody(), $native))->toPdf();
        $copy = (new DocumentReader())->read(new FileBytes($bytes));
        self::assertSame('q Q', $copy->streamData($copy->resolve($copy->catalog()->get('First'))));
        self::assertSame($source->bytes, $document->sourceRevision()->source->bytes);
    }

    public function testMultipleRootsShareIdentityButDifferentDocumentsAndGenerationsNeverCollide(): void
    {
        $fixture = new ImportSource();
        $document = (new DocumentReader())->read($fixture->file([3 => '(three)'], [3 => 7]));
        $reference = new ObjectReference($this->id(3, 7));
        $graph = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($reference, $reference, new ObjectReference($this->id(3)));
        self::assertSame($graph->roots[0], $graph->roots[1]);
        self::assertInstanceOf(NullType::class, $graph->roots[2]->target());
        self::assertSame('three', $graph->reference($this->id(3, 7))->target()->value);
        $other = (new DocumentReader())->read($fixture->file([3 => '(other)'], [3 => 7]));
        $second = (new ImportSession($other, StreamImportMode::PreserveEncoded))->import($reference);
        self::assertNotSame($graph->roots[0], $second->root());
        self::assertSame('other', $second->root()->target()->value);
    }

    public function testScalarBytesAndNumericTypesAreCopiedWithoutSerializingReadViews(): void
    {
        $document = (new DocumentReader())->read((new ImportSource())->file([3 => '[true false null 7 1.5 /A#20B (x\\000y) <00ff>]']));
        $graph = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import(new ObjectReference($this->id(3)));
        self::assertSame('[true false null 7 1.5 /A#20B (x\\000y) <00FF>]', $graph->root()->target()->toPdf(new SerializationContext()));
    }

    #[DataProvider('limits')]
    public function testBudgetsFailAtomicallyAndSessionsCannotResume(ImportLimits $limits, string $value): void
    {
        $document = (new DocumentReader())->read((new ImportSource())->file([3 => $value]));
        $session = new ImportSession($document, StreamImportMode::PreserveEncoded, $limits);
        try {
            $session->import(new ObjectReference($this->id(3)));
            self::fail('Expected a bounded import failure.');
        } catch (ImportException) {
            $this->expectException(\LogicException::class);
            $session->import(new NullType());
        }
    }

    /** @return iterable<string, array{ImportLimits, string}> */
    public static function limits(): iterable
    {
        yield 'objects' => [new ImportLimits(maxObjects: 1), '[1 0 R]'];
        yield 'values' => [new ImportLimits(maxValues: 3), '[null null null]'];
        yield 'depth' => [new ImportLimits(maxDepth: 2), '[[[null]]]'];
        yield 'bytes' => [new ImportLimits(maxBytes: 250), '(one string)'];
        yield 'cycles' => [new ImportLimits(maxCycles: 0), '[3 0 R]'];
    }

    #[DataProvider('forbidden')]
    public function testSignatureSecurityAndTransportDictionariesAreRejected(string $object): void
    {
        $document = (new DocumentReader())->read((new ImportSource())->file([3 => $object]));
        $this->expectException(ImportException::class);
        (new ImportSession($document, StreamImportMode::PreserveEncoded))->import(new ObjectReference($this->id(3)));
    }

    /** @return iterable<string, array{string}> */
    public static function forbidden(): iterable
    {
        yield 'signature' => ['<< /Type /Sig >>'];
        yield 'timestamp' => ['<< /Type /DocTimeStamp >>'];
        yield 'untyped signature' => ['<< /ByteRange [0 1 2 3] /Contents <00> >>'];
        yield 'signature without Type or ByteRange' => ['<< /Contents <00> /SubFilter /adbe.pkcs7.detached >>'];
        yield 'signed field' => ['<< /FT /Sig /V << /Contents <00> >> >>'];
        yield 'encryption' => ['<< /Filter /Standard /V 4 /R 4 >>'];
        yield 'public key encryption' => ['<< /Filter /Adobe.PubSec >>'];
        yield 'trailer encryption' => ['<< /Encrypt <<>> >>'];
        yield 'MAC' => ['<< /AuthCode <<>> >>'];
        yield 'object transport' => ['<< /Type /ObjStm >>'];
        yield 'xref transport' => ['<< /Type /XRef >>'];
    }

    public function testUnsignedSubgraphOfASignedDocumentIsAvailableWithoutSourceRevisionAuthority(): void
    {
        $document = (new DocumentReader())->read((new ImportSource())->file([
            1 => '<< /Type /Catalog /Pages 2 0 R /Signature 3 0 R >>',
            3 => '<< /Type /Sig /Contents <00> >>',
            4 => '<< /FT /Sig /V null /T (Unsigned field) >>',
        ]));
        $graph = (new ImportSession($document, StreamImportMode::PreserveEncoded))->import($document->catalog()->get('Pages'), new ObjectReference($this->id(4)));
        self::assertCount(2, $graph->roots);
        self::assertInstanceOf(NullType::class, $graph->roots[1]->target()->get('V'));
        self::assertFalse(method_exists($graph, 'toRevision'));
    }

    public function testDirectRootCyclesCannotBypassTheCycleAllowance(): void
    {
        $document = (new DocumentReader())->read((new ImportSource())->file([1 => '<< /Type /Catalog /Pages 2 0 R /Self 1 0 R >>']));
        $this->expectException(ImportException::class);
        (new ImportSession($document, StreamImportMode::PreserveEncoded, new ImportLimits(maxCycles: 0)))->import($document->catalog());
    }

    public function testIndirectAliasCycleHasNoContainerToSealAndFailsExplicitly(): void
    {
        $document = (new DocumentReader())->read((new ImportSource())->file([3 => '4 0 R', 4 => '3 0 R']));
        $this->expectException(ImportException::class);
        (new ImportSession($document, StreamImportMode::PreserveEncoded))->import(new ObjectReference($this->id(3)));
    }

    public function testContainersCannotBeResealedAfterTheyAreReturned(): void
    {
        $dictionary = new ImportedDictionary();
        $dictionary->seal(new Entry(new NameType('Value'), new LiteralString('first')));
        try {
            $dictionary->seal();
            self::fail('A dictionary must remain sealed.');
        } catch (\LogicException) {
            self::assertSame('first', $dictionary->get('Value')->value);
        }
        $array = new ImportedArray();
        $array->seal(new NullType());
        try {
            $array->seal();
            self::fail('An array must remain sealed.');
        } catch (\LogicException) {
            self::assertCount(1, $array);
        }
        $stream = new ImportedStream();
        $stream->seal('first', new DictionaryType());
        $this->expectException(\LogicException::class);
        $stream->seal('second', new DictionaryType());
    }

    private function id(int $number, int $generation = 0): ObjectIdentifier
    {
        return new ObjectIdentifier(new ObjectNumber($number), new GenerationNumber($generation));
    }
}
