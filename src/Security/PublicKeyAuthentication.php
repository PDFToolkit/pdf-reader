<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ObjectReference;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Security\Aes128Backend;
use PDF\Security\Aes256Backend;
use PDF\Security\AuthenticatedPublicKey;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileEncryptionKey;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PublicKeyKeyLength;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\PublicKeyRecipient;
use PDF\Security\PublicKeyRecipientScope;
use PDF\Security\PublicKeySubFilter;
use PDF\Security\RecipientDecrypter;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

/** Adobe.PubSec source authentication with caller-selected CMS capabilities and byte-order semantics. */
final readonly class PublicKeyAuthentication implements DocumentAuthentication
{
    /** @var non-empty-list<RecipientDecrypter> */
    private array $decrypters;

    /** @param RecipientDecrypter|non-empty-list<RecipientDecrypter> $decrypters */
    public function __construct(
        RecipientDecrypter|array $decrypters,
        private PublicKeyPermissionByteOrder $permissionByteOrder,
        private PublicKeyAuthenticationLimits $limits = new PublicKeyAuthenticationLimits(),
        private Aes128Backend $aes128 = new OpenSslAes128Backend(),
        private Aes256Backend $aes256 = new OpenSslAes256Backend(),
        private SourcePdfMacVerification $mac = new SourcePdfMacVerification(),
    ) {
        $identities = $decrypters instanceof RecipientDecrypter ? [$decrypters] : array_values($decrypters);
        if ($identities === [] || count($identities) > $limits->maxIdentities) {
            throw new \InvalidArgumentException('Public-key identities must be nonempty and within the configured limit.');
        }
        foreach ($identities as $identity) {
            if (!$identity instanceof RecipientDecrypter) {
                throw new \InvalidArgumentException('Every public-key identity must supply an explicit recipient-decryption capability.');
            }
        }
        $this->decrypters = $identities;
    }

    public function authenticate(ReadDocument $raw, DictionaryType $encryption, ?ObjectIdentifier $encryptionObject): DocumentDecryption
    {
        $values = new SecurityDictionaryValues($raw, $encryption);
        if ($values->name('Filter') !== 'Adobe.PubSec') {
            throw new UnsupportedPdfFeature('This public-key operation requires the Adobe.PubSec security handler.');
        }
        $subFilter = $values->name('SubFilter');
        $version = $values->integer('V', 0);
        $budget = new PublicKeyAuthenticationBudget($this->limits);
        $macIndicated = $values->value('KDFSalt') !== null
            || (new SecurityDictionaryValues($raw, $raw->trailer()))->value('AuthCode') !== null;
        if (in_array($subFilter, ['adbe.pkcs7.s3', 'adbe.pkcs7.s4'], true)) {
            return $this->legacy($raw, $values, $encryptionObject, $budget, $version, $subFilter, $macIndicated);
        }
        if ($subFilter !== 'adbe.pkcs7.s5' || !in_array($version, [4, 5, 6], true)) {
            throw new UnsupportedPdfFeature('The public-key subfilter and encryption version are unsupported or inconsistent.');
        }
        $strings = $values->name('StrF', 'Identity');
        $streams = $values->name('StmF', 'Identity');
        $embedded = $values->name('EFF', $streams);
        $cf = $values->value('CF');
        if (!$cf instanceof DictionaryType) {
            throw new ParseException('Public-key s5 encryption requires a crypt-filter dictionary.');
        }
        /** @var array<string, CryptFilterAccess> $filters */
        $filters = ['Identity' => new IdentityCryptFilterDecryption()];
        /** @var array<string, PublicKeyCryptFilterDecryption> $publicFilters */
        $publicFilters = [];
        /** @var array<string, bool> $open */
        $open = [];
        foreach ($cf->entries() as $entry) {
            $budget->filter();
            $name = $entry->key->value;
            if ($name === 'Identity') {
                // A CF definition cannot override the standard Identity filter.
                continue;
            }
            $filter = $raw->resolve($entry->value);
            if (!$filter instanceof DictionaryType) {
                throw new ParseException('Every public-key crypt-filter definition must be a dictionary.');
            }
            $fields = new SecurityDictionaryValues($raw, $filter);
            if ($fields->name('Type', 'CryptFilter') !== 'CryptFilter') {
                throw new ParseException('A public-key crypt-filter Type must be CryptFilter.');
            }
            $method = CryptMethod::tryFrom($fields->name('CFM', 'None'));
            if ($method === null || $method === CryptMethod::Identity) {
                throw new UnsupportedPdfFeature('The public-key crypt method is unsupported; CFM None requires a separate security-handler capability.');
            }
            if (($method === CryptMethod::Aes256 && $version < 5) || ($method === CryptMethod::AesGcm && $version < 6)) {
                throw new ParseException('The public-key crypt method requires a newer encryption version.');
            }
            $defaultLength = match ($method) {
                CryptMethod::Aes128 => 128,
                CryptMethod::Aes256, CryptMethod::AesGcm => 256,
                default => $values->integer('Length', 40),
            };
            $length = $this->length($fields->integer('Length', $defaultLength), $method);
            $event = $fields->name('AuthEvent', 'DocOpen');
            if (!in_array($event, ['DocOpen', 'EFOpen'], true)) {
                throw new ParseException('A public-key authentication event must be DocOpen or EFOpen.');
            }
            $documentDefault = in_array($name, [$strings, $streams], true);
            $recipients = $fields->value('Recipients');
            // TS 32004 sources its key from DefaultCryptFilter even when selectors differ.
            $scope = $documentDefault || ($version >= 5 && $name === 'DefaultCryptFilter' && $recipients instanceof ArrayType)
                ? PublicKeyRecipientScope::Document : PublicKeyRecipientScope::IndividualStream;
            $metadata = new EncryptMetadata($name === $streams ? $fields->boolean('EncryptMetadata', true) : true);
            $publicFilters[$name] = $this->filter($raw, $recipients, $method, $length, $scope, $metadata, $budget);
            $filters[$name] = $publicFilters[$name];
            // Table 25 defaults and the TS 32004 document key must be authorized at open.
            // In particular, deferring DefaultCryptFilter must not conceal its required-MAC bit.
            $open[$name] = $scope === PublicKeyRecipientScope::Document || $event === 'DocOpen';
        }
        foreach ([$strings, $streams, $embedded] as $selected) {
            if (!isset($filters[$selected])) {
                throw new ParseException('A public-key selector names an undefined crypt filter.');
            }
        }
        /** @var array<string, AuthenticatedPublicKey> $authenticated */
        $authenticated = [];
        foreach ($publicFilters as $name => $filter) {
            if ($open[$name]) {
                $authenticated[$name] = $filter->authenticate();
            }
        }
        /** @var array<string, AuthenticatedPublicKey> $permissions */
        $permissions = [];
        foreach (array_unique([$strings, $streams]) as $selected) {
            if ($selected !== 'Identity') {
                $permissions[$selected] = $authenticated[$selected];
            }
        }
        $macPermissions = 4096;
        foreach ($authenticated as $name => $authentication) {
            if ((isset($permissions[$name]) || $name === 'DefaultCryptFilter') && $authentication->permissions !== null) {
                $macPermissions &= $authentication->permissions->unsigned;
            }
        }
        $macLocation = null;
        if ($version < 5 && $macIndicated) {
            throw new UnsupportedPdfFeature('PDF MAC requires encryption version 5 or later.');
        }
        if ($version >= 5 && ($macIndicated || ($macPermissions & 4096) === 0)) {
            $default = $publicFilters['DefaultCryptFilter'] ?? throw new ParseException('Public-key PDF MAC requires DefaultCryptFilter.');
            $authentication = $default->authenticate();
            if (!$authentication->key instanceof FileEncryptionKey) {
                throw new ParseException('Public-key PDF MAC requires a 256-bit DefaultCryptFilter key.');
            }
            if ($authentication->permissions !== null) {
                $macPermissions &= $authentication->permissions->unsigned;
                $permissions['DefaultCryptFilter'] = $authentication;
            }
            $macLocation = $this->mac->verify($raw, $encryption, $authentication->key, $macPermissions);
        }
        $root = $raw->trailer()->get('Root');
        return new DocumentDecryption(
            new PublicKeyReadPermissions($permissions),
            ($publicFilters[$streams] ?? null)?->metadata->value ?? true,
            $encryptionObject,
            $filters,
            $strings,
            $streams,
            $embedded,
            $root instanceof ObjectReference ? $root : null,
            $macLocation
        );
    }

    private function legacy(
        ReadDocument $raw,
        SecurityDictionaryValues $values,
        ?ObjectIdentifier $encryptionObject,
        PublicKeyAuthenticationBudget $budget,
        int $version,
        string $subFilter,
        bool $macIndicated,
    ): DocumentDecryption {
        if (!in_array($version, [1, 2], true)) {
            throw new UnsupportedPdfFeature('Legacy public-key encryption requires version 1 or 2.');
        }
        if ($macIndicated) {
            throw new UnsupportedPdfFeature('PDF MAC requires encryption version 5 or later.');
        }
        foreach (['CF', 'StrF', 'StmF', 'EFF'] as $name) {
            if ($values->value($name) !== null) {
                throw new ParseException('Legacy public-key subfilters cannot select crypt filters.');
            }
        }
        $bits = $values->integer('Length', 40);
        if (($version === 1 || $subFilter === 'adbe.pkcs7.s3') && $bits !== 40) {
            throw new ParseException('The selected legacy public-key mechanism requires a 40-bit key.');
        }
        $budget->filter();
        $filter = $this->filter($raw, $values->value('Recipients'), CryptMethod::Rc4, $this->length($bits, CryptMethod::Rc4), PublicKeyRecipientScope::Document, new EncryptMetadata(true), $budget);
        $root = $raw->trailer()->get('Root');
        return new DocumentDecryption(
            new PublicKeyReadPermissions(['DefaultCryptFilter' => $filter->authenticate()], PublicKeySubFilter::from($subFilter)),
            true,
            $encryptionObject,
            ['Identity' => new IdentityCryptFilterDecryption(), 'DefaultCryptFilter' => $filter],
            'DefaultCryptFilter',
            'DefaultCryptFilter',
            'DefaultCryptFilter',
            $root instanceof ObjectReference ? $root : null
        );
    }

    private function length(int $bits, CryptMethod $method): PublicKeyKeyLength
    {
        try {
            $length = new PublicKeyKeyLength(new IntegerType($bits));
        } catch (\InvalidArgumentException $error) {
            throw new ParseException('The public-key encryption key length is malformed.');
        }
        if (($method === CryptMethod::Rc4 && $bits > 128)
            || ($method === CryptMethod::Aes128 && $bits !== 128)
            || (in_array($method, [CryptMethod::Aes256, CryptMethod::AesGcm], true) && $bits !== 256)) {
            throw new ParseException('The public-key crypt method and bit length disagree.');
        }
        return $length;
    }

    private function filter(
        ReadDocument $raw,
        ?BasicType $value,
        CryptMethod $method,
        PublicKeyKeyLength $length,
        PublicKeyRecipientScope $scope,
        EncryptMetadata $metadata,
        PublicKeyAuthenticationBudget $budget,
    ): PublicKeyCryptFilterDecryption {
        if ($scope === PublicKeyRecipientScope::Document) {
            if (!$value instanceof ArrayType || $value->items === []) {
                throw new ParseException('Document public-key recipients must be a nonempty array of CMS byte strings.');
            }
            $items = $value->items;
        } else {
            if (!$value instanceof StringType) {
                throw new ParseException('An individual-stream public-key recipient must be one CMS byte string.');
            }
            $items = [$value];
        }
        $recipients = [];
        foreach ($items as $item) {
            $bytes = $raw->resolve($item);
            if (!$bytes instanceof StringType) {
                throw new ParseException('A public-key recipient must resolve to CMS byte-string data.');
            }
            $budget->recipient(strlen($bytes->value));
            $recipients[] = new PublicKeyRecipient($bytes);
        }
        return new PublicKeyCryptFilterDecryption($method, $length, $scope, $metadata, $this->permissionByteOrder, $recipients, $this->decrypters, $budget, $this->aes128, $this->aes256);
    }
}
