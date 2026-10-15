<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** The standard Identity filter has no key and performs no authorization or transformation. */
final readonly class IdentityCryptFilterDecryption implements CryptFilterAccess
{
    public function decrypt(string $bytes, ObjectIdentifier $identifier, bool $explicit = false): string
    {
        return $bytes;
    }
}
