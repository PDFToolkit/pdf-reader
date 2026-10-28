<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\ByteCursor;
use PDF\Reading\EncodedStream;
use PDF\Reading\Filters\IdentityDecoder;
use PDF\Reading\ObjectReference;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\StreamDecoder;
use PDF\Reading\SyntaxParser;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingIdentityFilterTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function inputs(): iterable
    {
        yield 'missing parameters' => ['/Filter /Crypt', "hello\0\xff", "hello\0\xff"];
        yield 'null parameters' => ['/Filter /Crypt /DecodeParms null', 'hello', 'hello'];
        yield 'default name' => ['/Filter /Crypt /DecodeParms << /Type /CryptFilterDecodeParms >>', 'hello', 'hello'];
        yield 'explicit identity and private field' => ['/Filter /Crypt /DecodeParms << /Name /Identity /Vendor_Info (retained) >>', 'hello', 'hello'];
        yield 'filter chain' => ['/Filter [/Crypt /FlateDecode] /DecodeParms [<< /Name /Identity >> null]', gzcompress('hello'), 'hello'];
        yield 'array with omitted parameters' => ['/Filter [/Crypt /ASCIIHexDecode]', '68656c6c6f>', 'hello'];
    }

    #[DataProvider('inputs')]
    public function testStandardIdentityDoesNotRequireAnEncryptionDictionary(string $fields, string $bytes, string $expected): void
    {
        $stream = new EncodedStream($this->dictionary($fields), $bytes);
        self::assertSame($expected, (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
        self::assertSame($bytes, $stream->encodedBytes());
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'wrong Type name' => ['/Filter /Crypt /DecodeParms << /Type /Other >>'];
        yield 'wrong Type object' => ['/Filter /Crypt /DecodeParms << /Type 42 >>'];
        yield 'wrong Name object' => ['/Filter /Crypt /DecodeParms << /Name (Identity) >>'];
        yield 'named decryption needs context' => ['/Filter /Crypt /DecodeParms << /Name /Secure >>'];
        yield 'Crypt second' => ['/Filter [/ASCIIHexDecode /Crypt]'];
        yield 'Crypt repeated' => ['/Filter [/Crypt /Crypt]'];
    }

    #[DataProvider('invalid')]
    public function testInvalidOrUnauthenticatedCryptSelectionFails(string $fields): void
    {
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode(new EncodedStream($this->dictionary($fields), '>'), new ReadBudget(new ReaderLimits()));
    }

    public function testIndirectCryptFieldsResolveInTheOwningContext(): void
    {
        $resolver = new class () implements ObjectResolver {
            public function resolve(BasicType $value): BasicType
            {
                if ($value instanceof ObjectReference) {
                    return new NameType($value->identifier->objectNumber->value === 1 ? 'CryptFilterDecodeParms' : 'Identity');
                }
                return $value;
            }
        };
        $stream = new EncodedStream($this->dictionary('/Filter /Crypt /DecodeParms << /Type 1 0 R /Name 2 0 R >>'), 'hello');
        self::assertSame('hello', (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits()), $resolver));
        $this->expectException(ParseException::class);
        (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits()));
    }

    public function testIdentityOutputCountsAgainstBothDecodedLimits(): void
    {
        foreach ([new ReaderLimits(maxDecodedStreamBytes: 3), new ReaderLimits(maxTotalDecodedBytes: 3)] as $limits) {
            try {
                (new IdentityDecoder($limits))->decode('hello', new ReadBudget($limits));
                self::fail('Identity must retain the configured bounds.');
            } catch (ParseException) {
                self::assertTrue(true);
            }
        }
    }

    private function dictionary(string $fields): DictionaryType
    {
        $value = (new SyntaxParser(new ByteCursor('<< ' . $fields . ' >>')))->read();
        self::assertInstanceOf(DictionaryType::class, $value);
        return $value;
    }
}
