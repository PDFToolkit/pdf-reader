<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** One document-local filter capability; deferred authorization must fail before returning plaintext. */
interface CryptFilterAccess
{
    public function decrypt(string $bytes, ObjectIdentifier $identifier, bool $explicit = false): string;
}
