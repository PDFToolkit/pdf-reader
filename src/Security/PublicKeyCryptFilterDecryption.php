<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;
use PDF\Security\Aes128Backend;
use PDF\Security\Aes256Backend;
use PDF\Security\AuthenticatedPublicKey;
use PDF\Security\EncryptMetadata;
use PDF\Security\PublicKeyKeyDerivation;
use PDF\Security\PublicKeyKeyLength;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\PublicKeyPermissions;
use PDF\Security\PublicKeyRecipient;
use PDF\Security\PublicKeyRecipientScope;
use PDF\Security\PublicKeySeed;
use PDF\Security\RecipientDecrypter;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** One independent source key; EFOpen can defer this bounded operation until first access. */
final class PublicKeyCryptFilterDecryption implements CryptFilterAccess
{
    private ?AuthenticatedPublicKey $authenticated = null;
    private ?CryptFilterDecryption $operation = null;
    private ?ParseException $failure = null;

    /**
     * @param non-empty-list<PublicKeyRecipient> $recipients Original, ordered CMS packets.
     * @param non-empty-list<RecipientDecrypter> $decrypters Application-selected identities.
     */
    public function __construct(
        public readonly CryptMethod $method,
        private readonly PublicKeyKeyLength $length,
        public readonly PublicKeyRecipientScope $scope,
        public readonly EncryptMetadata $metadata,
        private readonly PublicKeyPermissionByteOrder $permissionByteOrder,
        private readonly array $recipients,
        private readonly array $decrypters,
        private readonly PublicKeyAuthenticationBudget $budget,
        private readonly Aes128Backend $aes128,
        private readonly Aes256Backend $aes256,
    ) {
        if ($method === CryptMethod::Identity || $recipients === [] || $decrypters === []
            || ($scope === PublicKeyRecipientScope::IndividualStream && (count($recipients) !== 1 || !$metadata->value))) {
            throw new \InvalidArgumentException('A public-key crypt filter requires cipher, recipients and identities consistent with its scope.');
        }
    }

    public function authenticate(): AuthenticatedPublicKey
    {
        if ($this->authenticated !== null) {
            return $this->authenticated;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        try {
            $authenticated = $this->recover();
            $this->operation = new CryptFilterDecryption($this->method, $authenticated->key, $this->aes128, $this->aes256);
            $this->authenticated = $authenticated;
            return $this->authenticated;
        } catch (ParseException $error) {
            $this->failure = $error;
            throw $error;
        } catch (\InvalidArgumentException|\UnexpectedValueException|\LengthException $error) {
            $this->failure = new ParseException('The public-key recipient payload or derived key is malformed.');
            throw $this->failure;
        }
    }

    public function decrypt(string $bytes, ObjectIdentifier $identifier, bool $explicit = false): string
    {
        if ($explicit && $this->scope !== PublicKeyRecipientScope::IndividualStream) {
            throw new ParseException('An explicit public-key Crypt filter requires a seed-only individual-stream recipient.');
        }
        $this->authenticate();
        return ($this->operation ?? throw new \LogicException('Authenticated public-key operation is missing.'))->decrypt($bytes, $identifier, $explicit);
    }

    private function recover(): AuthenticatedPublicKey
    {
        // Recipient order has priority over identity order: the first matching list determines rights.
        foreach ($this->recipients as $index => $recipient) {
            foreach ($this->decrypters as $decrypter) {
                $this->budget->attempt();
                try {
                    $payload = $decrypter->decrypt($recipient);
                } catch (\Throwable $error) {
                    // Application backends may include private-key data in their exceptions.
                    // Do not attach that exception as a cause or expose its original message.
                    throw new ParseException('The supplied public-key recipient-decryption capability failed.');
                }
                if ($payload === null) {
                    continue;
                }
                $expectedLength = $this->scope === PublicKeyRecipientScope::Document ? 24 : 20;
                if (strlen($payload) !== $expectedLength) {
                    throw new ParseException('The decrypted public-key recipient payload has the wrong scope-specific length.');
                }
                $permissions = null;
                if ($expectedLength === 24) {
                    $word = unpack($this->permissionByteOrder->value . 'permissions', substr($payload, 20));
                    if ($word === false || !isset($word['permissions']) || !is_int($word['permissions'])) {
                        throw new ParseException('The authenticated public-key permission word is malformed.');
                    }
                    $permissions = new PublicKeyPermissions(new IntegerType($word['permissions']));
                }
                $key = (new PublicKeyKeyDerivation())->derive(
                    new PublicKeySeed(new HexadecimalString(substr($payload, 0, 20))),
                    $this->length,
                    $this->metadata,
                    ...$this->recipients
                );
                return new AuthenticatedPublicKey($key, $permissions, $index);
            }
        }
        throw new PublicKeyAuthenticationFailed();
    }
}
