<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;
use PDF\Security\Aes128Backend;
use PDF\Security\Aes256Backend;
use PDF\Security\AesGcmCipher;
use PDF\Security\FileEncryptionKey;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalEncryptionKey;
use PDF\Security\HistoricalObjectKeyDerivation;
use PDF\Security\InitializationVector;
use PDF\Security\Rc4Cipher;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** One immutable named crypt-filter capability, using the core's native cipher operations. */
final readonly class CryptFilterDecryption
{
    public function __construct(
        public CryptMethod $method,
        private FileEncryptionKey|HistoricalEncryptionKey $key,
        private Aes128Backend $aes128,
        private Aes256Backend $aes256,
    ) {
        $length = strlen($key->bytes());
        if (($method === CryptMethod::Aes128 && $length !== 16)
            || (in_array($method, [CryptMethod::Aes256, CryptMethod::AesGcm], true) && $length !== 32)
            || ($method === CryptMethod::Rc4 && ($length < 5 || $length > 16))) {
            throw new ParseException('The crypt method and authenticated key length disagree.');
        }
    }

    public function decrypt(string $bytes, ObjectIdentifier $identifier, bool $explicit = false): string
    {
        try {
            return $this->apply($bytes, $identifier, $explicit);
        } catch (\InvalidArgumentException|\UnexpectedValueException $error) {
            throw new ParseException('The encrypted object data failed cipher validation.');
        }
    }

    private function apply(string $bytes, ObjectIdentifier $identifier, bool $explicit): string
    {
        if ($this->method === CryptMethod::Identity) {
            return $bytes;
        }
        if ($this->method === CryptMethod::AesGcm) {
            return (new AesGcmCipher())->decrypt($bytes, new FileEncryptionKey($this->key->bytes()));
        }
        if ($this->method === CryptMethod::Aes256) {
            $this->validateCbc($bytes);
            return $this->aes256->decryptCbc(substr($bytes, 16), new FileEncryptionKey($this->key->bytes()), new InitializationVector(substr($bytes, 0, 16)), true);
        }
        $key = new HistoricalEncryptionKey($this->key->bytes());
        if (!$explicit) {
            $key = (new HistoricalObjectKeyDerivation())->derive(
                $key,
                $identifier,
                $this->method === CryptMethod::Aes128 ? HistoricalDataCipher::Aes128 : HistoricalDataCipher::Rc4
            );
        }
        if ($this->method === CryptMethod::Rc4) {
            return (new Rc4Cipher())->transform($bytes, $key);
        }
        $this->validateCbc($bytes);
        return $this->aes128->decryptCbc(substr($bytes, 16), $key, new InitializationVector(substr($bytes, 0, 16)), true);
    }

    private function validateCbc(string $bytes): void
    {
        if (strlen($bytes) < 32 || (strlen($bytes) - 16) % 16 !== 0) {
            throw new ParseException('AES-CBC stream data requires an IV and complete padded blocks.');
        }
    }
}
