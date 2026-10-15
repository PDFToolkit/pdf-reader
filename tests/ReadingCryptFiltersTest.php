<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\EncodedStream;
use PDF\Reading\ObjectResolver;
use PDF\Reading\ParseException;
use PDF\Reading\ReadBudget;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\CryptFilterDecryption;
use PDF\Reading\Security\CryptMethod;
use PDF\Reading\Security\DocumentDecryption;
use PDF\Reading\Security\ReadPermissions;
use PDF\Reading\StreamDecoder;
use PDF\Security\AesGcmCipher;
use PDF\Security\FileEncryptionKey;
use PDF\Security\InitializationVector;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PasswordAccess;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\TestCase;

final class ReadingCryptFiltersTest extends TestCase
{
    public function testExplicitCryptIsAppliedOnceBeforeTheRemainingFiltersAndRetainsCiphertext(): void
    {
        $key = new FileEncryptionKey(str_repeat('k', 32));
        $compressed = (new FlateDecode())->encode('visible decrypted stream');
        $iv = new InitializationVector(str_repeat('i', 16));
        $ciphertext = $iv->value . (new OpenSslAes256Backend())->encryptCbc($compressed, $key, $iv, true);
        $dictionary = new DictionaryType(
            new Entry(new NameType('Filter'), new ArrayType(new NameType('Crypt'), new NameType('FlateDecode'))),
            new Entry(new NameType('DecodeParms'), new ArrayType(new DictionaryType(new Entry(new NameType('Name'), new NameType('Secure'))), new NullType())),
        );
        $stream = $this->context()->object(new EncodedStream($dictionary, $ciphertext), $this->identifier(), $this->resolver(), new ReadBudget(new ReaderLimits()));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame($ciphertext, $stream->originalEncodedBytes());
        self::assertSame($compressed, $stream->encodedBytes());
        self::assertSame('visible decrypted stream', (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
    }

    public function testCryptWithoutParametersDefaultsToIdentityDespiteAnEncryptedStreamDefault(): void
    {
        $stream = $this->context()->object(new EncodedStream(new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('Crypt')),
        ), 'identity bytes'), $this->identifier(), $this->resolver(), new ReadBudget(new ReaderLimits()));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame('identity bytes', (new StreamDecoder())->decode($stream, new ReadBudget(new ReaderLimits())));
        self::assertNull($stream->dictionary()->get('Filter'));
    }

    public function testCryptCannotOccurAfterADecompressionFilter(): void
    {
        $this->expectException(ParseException::class);
        $this->context()->object(
            new EncodedStream(new DictionaryType(new Entry(
                new NameType('Filter'),
                new ArrayType(new NameType('FlateDecode'), new NameType('Crypt'))
            )), ''),
            $this->identifier(),
            $this->resolver(),
            new ReadBudget(new ReaderLimits())
        );
    }

    public function testAesGcmRejectsModifiedTagsWithoutReturningUnauthenticatedPlaintext(): void
    {
        $key = new FileEncryptionKey(str_repeat('k', 32));
        $encrypted = (new AesGcmCipher())->encrypt('authenticated GCM', $key, str_repeat('n', 12));
        $filter = new CryptFilterDecryption(CryptMethod::AesGcm, $key, new OpenSslAes128Backend(), new OpenSslAes256Backend());
        self::assertSame('authenticated GCM', $filter->decrypt($encrypted, $this->identifier()));
        $encrypted[strlen($encrypted) - 1] = chr(ord($encrypted[strlen($encrypted) - 1]) ^ 1);
        $this->expectException(ParseException::class);
        $filter->decrypt($encrypted, $this->identifier());
    }

    public function testOwnerAuthenticationReportsAllGrantedPermissionsWithoutLosingTheSourceMask(): void
    {
        $owner = new ReadPermissions(-44, PasswordAccess::Owner, 6);
        $user = new ReadPermissions(-44, PasswordAccess::User, 6);
        self::assertSame(-44, $owner->mask);
        self::assertSame(-4, $owner->effectiveMask());
        self::assertSame(-44, $user->effectiveMask());
    }

    private function context(): DocumentDecryption
    {
        $key = new FileEncryptionKey(str_repeat('k', 32));
        $aes128 = new OpenSslAes128Backend();
        $aes256 = new OpenSslAes256Backend();
        return new DocumentDecryption(new ReadPermissions(-4, PasswordAccess::User, 6), true, null, [
            'Identity' => new CryptFilterDecryption(CryptMethod::Identity, $key, $aes128, $aes256),
            'Secure' => new CryptFilterDecryption(CryptMethod::Aes256, $key, $aes128, $aes256),
        ], 'Secure', 'Secure', 'Secure');
    }

    private function identifier(): ObjectIdentifier
    {
        return new ObjectIdentifier(new ObjectNumber(17));
    }

    private function resolver(): ObjectResolver
    {
        return new class () implements ObjectResolver {
            public function resolve(BasicType $value): BasicType
            {
                return $value;
            }
        };
    }
}
