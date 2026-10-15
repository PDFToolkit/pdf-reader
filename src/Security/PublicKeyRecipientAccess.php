<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Security\PublicKeyPermissions;

/** Authenticated CMS facts, without private credentials or a password-owner identity. */
final readonly class PublicKeyRecipientAccess
{
    public function __construct(public ?PublicKeyPermissions $permissions, public int $recipientIndex)
    {
        if ($recipientIndex < 0) {
            throw new \InvalidArgumentException('A recipient index must be nonnegative.');
        }
    }

    public function effectiveMask(): int
    {
        if ($this->permissions === null || $this->permissions->ownerAccess()) {
            return -4;
        }
        $word = $this->permissions->unsigned;
        return $word > 0x7fffffff ? $word - 0x100000000 : $word;
    }
}
