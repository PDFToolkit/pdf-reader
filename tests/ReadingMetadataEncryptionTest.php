<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\EncodedStream;
use PDF\Reading\ObjectReference;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\CryptFilterDecryption;
use PDF\Reading\Security\CryptMethod;
use PDF\Reading\Security\DocumentDecryption;
use PDF\Reading\Security\ReadPermissions;
use PDF\Security\FileEncryptionKey;
use PDF\Security\InitializationVector;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PasswordAccess;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\TestCase;

final class ReadingMetadataEncryptionTest extends TestCase
{
    public function testOnlyTheCatalogMetadataIdentityIsImplicitlyClear(): void
    {
        foreach ([new NameType('Metadata'), $this->reference(25)] as $type) {
            $stream = new EncodedStream(new DictionaryType(new Entry(new NameType('Type'), $type)), $this->encrypt('<page>private</page>'));
            $result = $this->read($stream, 18);
            self::assertSame('<page>private</page>', $result->encodedBytes());
            self::assertSame($stream->encodedBytes(), $result->originalEncodedBytes());

            $catalog = $this->read(new EncodedStream($stream->dictionary(), '<catalog>public</catalog>'), 17);
            self::assertSame('<catalog>public</catalog>', $catalog->encodedBytes());
        }
    }

    public function testNonCatalogMetadataCanExplicitlySelectAnEncryptingFilter(): void
    {
        $dictionary = new DictionaryType(
            new Entry(new NameType('Type'), new NameType('Metadata')),
            new Entry(new NameType('Filter'), new NameType('Crypt')),
            new Entry(new NameType('DecodeParms'), new DictionaryType(new Entry(new NameType('Name'), new NameType('Secure')))),
        );
        self::assertSame('private metadata', $this->read(new EncodedStream($dictionary, $this->encrypt('private metadata')), 18)->encodedBytes());
    }

    public function testExplicitIdentityElsewherePreservesTheRemainingCodecInput(): void
    {
        $compressed = gzcompress('clear non-catalog metadata');
        self::assertNotFalse($compressed);
        $stream = new EncodedStream(new DictionaryType(
            new Entry(new NameType('Type'), new NameType('Metadata')),
            new Entry(new NameType('Filter'), new ArrayType(new NameType('Crypt'), new NameType('FlateDecode'))),
            new Entry(new NameType('DecodeParms'), new ArrayType(new DictionaryType(new Entry(new NameType('Name'), new NameType('Identity'))), new NullType())),
        ), $compressed);
        $result = $this->read($stream, 18);
        self::assertSame($compressed, $result->encodedBytes());
        self::assertSame('FlateDecode', $result->dictionary()->get('Filter')->items[0]->value);
    }

    public function testIndirectEmbeddedTypeSelectsEffInsteadOfTheOrdinaryStreamFilter(): void
    {
        $dictionary = new DictionaryType(new Entry(new NameType('Type'), $this->reference(26)));
        self::assertSame('clear embedded bytes', $this->read(new EncodedStream($dictionary, 'clear embedded bytes'), 18)->encodedBytes());
        self::assertSame('encrypted embedded bytes', $this->read(new EncodedStream($dictionary, $this->encrypt('encrypted embedded bytes')), 18, 'Identity', 'Secure')->encodedBytes());
    }

    private function read(EncodedStream $stream, int $number, string $streams = 'Secure', string $embedded = 'Identity'): EncodedStream
    {
        $key = new FileEncryptionKey(str_repeat('k', 32));
        $aes128 = new OpenSslAes128Backend();
        $aes256 = new OpenSslAes256Backend();
        $decryption = new DocumentDecryption(new ReadPermissions(-4, PasswordAccess::User, 6), false, null, [
            'Identity' => new CryptFilterDecryption(CryptMethod::Identity, $key, $aes128, $aes256),
            'Secure' => new CryptFilterDecryption(CryptMethod::Aes256, $key, $aes128, $aes256),
        ], 'Identity', $streams, $embedded, $this->reference(1));
        $resolver = new class () implements ObjectResolver {
            public function resolve(BasicType $value): BasicType
            {
                if (!$value instanceof ObjectReference) {
                    return $value;
                }
                return match ($value->identifier->objectNumber->value) {
                    1 => new DictionaryType(new Entry(new NameType('Metadata'), new ObjectReference(new ObjectIdentifier(new ObjectNumber(17))))),
                    25 => new NameType('Metadata'),
                    26 => new NameType('EmbeddedFile'),
                    default => new NullType(),
                };
            }
        };
        $result = $decryption->object($stream, new ObjectIdentifier(new ObjectNumber($number)), $resolver, new ReadBudget(new ReaderLimits()));
        self::assertInstanceOf(EncodedStream::class, $result);
        return $result;
    }

    private function reference(int $number): ObjectReference
    {
        return new ObjectReference(new ObjectIdentifier(new ObjectNumber($number)));
    }

    private function encrypt(string $bytes): string
    {
        $iv = new InitializationVector(str_repeat('i', 16));
        return $iv->value . (new OpenSslAes256Backend())->encryptCbc($bytes, new FileEncryptionKey(str_repeat('k', 32)), $iv, true);
    }
}
