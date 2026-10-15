<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Security\Aes128Backend;
use PDF\Security\Aes256Backend;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileEncryptionKey;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalEncryptionKey;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalOwnerPasswordEntry;
use PDF\Security\HistoricalPermissions;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardEncryptionDictionary;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\ModernEncryptionDictionary;
use PDF\Security\ModernSecurityRevision;
use PDF\Security\ModernStandardSecurityHandler;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\Pdf20Permissions;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Security\Revision5EncryptionDictionary;
use PDF\Security\Revision5Permissions;
use PDF\Security\SystemRandomSource;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Explicit ISO/Adobe Standard-handler authentication; no password discovery or external process. */
final readonly class StandardPasswordAuthentication implements DocumentAuthentication
{
    public function __construct(
        private PreparedPassword|PdfDocEncodedPassword $password,
        private Aes128Backend $aes128 = new OpenSslAes128Backend(),
        private Aes256Backend $aes256 = new OpenSslAes256Backend(),
    ) {
    }

    public function authenticate(ReadDocument $raw, DictionaryType $encryption, ?ObjectIdentifier $encryptionObject): DocumentDecryption
    {
        $values = new SecurityDictionaryValues($raw, $encryption);
        if ($values->name('Filter') !== 'Standard') {
            throw new UnsupportedPdfFeature('This password operation requires the Standard security handler.');
        }
        $authCode = $raw->trailer()->get('AuthCode');
        $authCode = $authCode === null ? null : $raw->resolve($authCode);
        if ($values->value('KDFSalt') !== null || ($authCode !== null && !$authCode instanceof NullType)) {
            throw new UnsupportedPdfFeature('A source PDF MAC must be verified before password-protected content is interpreted.');
        }
        $revision = $values->integer('R');
        $version = $values->integer('V', 0);
        $expected = match ($revision) {
            2 => 1, 3 => 2, 4 => 4, 5, 6 => 5, 7 => 6, default => null
        };
        if ($expected === null || $version !== $expected) {
            throw new UnsupportedPdfFeature('The Standard encryption revision and version are unsupported or inconsistent.');
        }
        $permissions = $values->integer('P');
        $metadata = new EncryptMetadata($values->boolean('EncryptMetadata', true));
        try {
            if ($revision <= 4) {
                if (!$this->password instanceof PdfDocEncodedPassword) {
                    throw new UnsupportedPdfFeature('Historical revisions require an explicitly PDFDocEncodedPassword.');
                }
                $id = $raw->trailer()->get('ID');
                $id = $id === null ? null : $raw->resolve($id);
                if (!$id instanceof ArrayType || !($id->items[0] ?? null) instanceof StringType) {
                    throw new ParseException('Historical encryption requires the first file identifier.');
                }
                $historicalRevision = HistoricalSecurityRevision::from($revision);
                $dictionary = new HistoricalStandardEncryptionDictionary(
                    $historicalRevision,
                    HistoricalDataCipher::Rc4,
                    new HistoricalKeyLength(new IntegerType($revision === 2 ? 40 : $values->integer('Length', 40))),
                    new HistoricalOwnerPasswordEntry(new HexadecimalString($values->bytes('O'))),
                    new HexadecimalString($values->bytes('U')),
                    new HistoricalPermissions(new IntegerType($permissions), $historicalRevision),
                    $metadata
                );
                $authenticated = (new HistoricalStandardSecurityHandler())->authenticate($this->password, $dictionary, new FileIdentifier($id->items[0]));
            } else {
                if (!$this->password instanceof PreparedPassword) {
                    throw new UnsupportedPdfFeature('AES-256 revisions require an explicitly prepared UTF-8 password.');
                }
                if ($values->integer('Length', 256) !== 256) {
                    throw new ParseException('AES-256 Standard encryption requires a 256-bit key.');
                }
                $arguments = [$values->bytes('O'), $values->bytes('U'), $values->bytes('OE'), $values->bytes('UE'), $values->bytes('Perms')];
                if ($revision === 5) {
                    $dictionary = new Revision5EncryptionDictionary(...[...$arguments, new Revision5Permissions($permissions), $metadata]);
                    $authenticated = (new HistoricalRevision5SecurityHandler($this->aes256, new SystemRandomSource()))->authenticate($this->password, $dictionary);
                } else {
                    $dictionary = new ModernEncryptionDictionary(...[...$arguments, new Pdf20Permissions($permissions), $metadata,
                        $revision === 7 ? ModernSecurityRevision::Aes256Gcm : ModernSecurityRevision::Aes256Cbc]);
                    $authenticated = (new ModernStandardSecurityHandler($this->aes256, new SystemRandomSource()))->authenticate($this->password, $dictionary);
                }
            }
        } catch (\InvalidArgumentException|\UnexpectedValueException $error) {
            throw new ParseException('The Standard encryption credentials or authenticated permissions are malformed.');
        }
        if ($authenticated === null) {
            throw new AuthenticationFailed();
        }
        $key = $authenticated->key;
        $filters = ['Identity' => new CryptFilterDecryption(CryptMethod::Identity, $key, $this->aes128, $this->aes256)];
        if ($revision <= 3) {
            $filters['StdCF'] = new CryptFilterDecryption(CryptMethod::Rc4, $key, $this->aes128, $this->aes256);
            $strings = $streams = $embedded = 'StdCF';
        } else {
            $cf = $values->value('CF');
            if ($cf !== null && !$cf instanceof DictionaryType) {
                throw new ParseException('CF must resolve to a crypt-filter dictionary.');
            }
            foreach ($cf?->entries() ?? [] as $entry) {
                if ($entry->key->value === 'Identity') {
                    throw new ParseException('Identity cannot be redefined as a crypt-filter name.');
                }
                $filter = $raw->resolve($entry->value);
                if (!$filter instanceof DictionaryType) {
                    throw new ParseException('Every crypt-filter definition must be a dictionary.');
                }
                $fields = new SecurityDictionaryValues($raw, $filter);
                $method = CryptMethod::tryFrom($fields->name('CFM', 'None')) ?? throw new UnsupportedPdfFeature('The selected crypt method is unsupported.');
                $allowed = $revision === 4 ? [CryptMethod::Identity, CryptMethod::Rc4, CryptMethod::Aes128]
                    : [CryptMethod::Identity, $revision === 7 ? CryptMethod::AesGcm : CryptMethod::Aes256];
                if (!in_array($method, $allowed, true) || !in_array($fields->name('AuthEvent', 'DocOpen'), ['DocOpen', 'EFOpen'], true)
                    || $fields->integer('Length', strlen($key->bytes())) !== strlen($key->bytes())) {
                    throw new ParseException('The crypt-filter method, key length or authentication event is inconsistent.');
                }
                $filters[$entry->key->value] = new CryptFilterDecryption($method, $key, $this->aes128, $this->aes256);
            }
            $strings = $values->name('StrF', 'Identity');
            $streams = $values->name('StmF', 'Identity');
            $embedded = $values->name('EFF', $streams);
        }
        return new DocumentDecryption(
            new ReadPermissions($permissions, $authenticated->access, $revision),
            $metadata->value,
            $encryptionObject,
            $filters,
            $strings,
            $streams,
            $embedded
        );
    }
}
