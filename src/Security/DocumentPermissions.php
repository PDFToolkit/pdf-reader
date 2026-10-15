<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

/** Document usage permissions authenticated by the selected security handler. */
interface DocumentPermissions
{
    public function effectiveMask(): int;
}
