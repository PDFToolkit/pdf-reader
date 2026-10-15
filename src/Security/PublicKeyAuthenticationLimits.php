<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

/** Explicit ceilings for CMS recipients and private-key work during one document read. */
final readonly class PublicKeyAuthenticationLimits
{
    public function __construct(
        public int $maxFilters = 64,
        public int $maxRecipients = 1024,
        public int $maxRecipientBytes = 1048576,
        public int $maxTotalRecipientBytes = 16777216,
        public int $maxCredentialAttempts = 4096,
        public int $maxIdentities = 32,
    ) {
        foreach (get_object_vars($this) as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Public-key authentication limits must be positive integers.');
            }
        }
    }
}
