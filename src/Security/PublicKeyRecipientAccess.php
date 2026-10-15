<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Security\PublicKeyPermissions;
use PDF\Security\PublicKeySubFilter;

/** Authenticated CMS facts, without private credentials or a password-owner identity. */
final readonly class PublicKeyRecipientAccess
{
    public function __construct(
        public ?PublicKeyPermissions $permissions,
        public int $recipientIndex,
        public PublicKeySubFilter $subFilter = PublicKeySubFilter::Pkcs7S5,
    ) {
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
        if ($this->subFilter === PublicKeySubFilter::Pkcs7S3) {
            // S3 uses R2 usage semantics: source bits 9–12 are reserved. Translate
            // the four broad rights into the common mask without granting any
            // right from a reserved source bit (ISO 32000-2, 7.6.5.3).
            $word &= ~0xf00;
            $word |= ($word & 32) << 3; // Form filling follows annotation permission.
            $word |= ($word & 16) << 5; // Accessibility extraction follows copying.
            $word |= ($word & 8) << 7; // Assembly follows content modification.
            $word |= ($word & 4) << 9; // Printing has no separate quality restriction.
        }
        return $word > 0x7fffffff ? $word - 0x100000000 : $word;
    }
}
