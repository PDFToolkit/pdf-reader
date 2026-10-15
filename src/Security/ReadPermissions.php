<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Security\PasswordAccess;

/** Authenticated source permissions; enforcement belongs to the consuming application. */
final readonly class ReadPermissions implements DocumentPermissions
{
    public function __construct(public int $mask, public PasswordAccess $access, public int $revision)
    {
    }

    public function effectiveMask(): int
    {
        return $this->access === PasswordAccess::Owner ? -4 : $this->mask;
    }
}
