<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;

final class AuthenticationFailed extends ParseException
{
    public function __construct()
    {
        parent::__construct('The supplied password did not authenticate this PDF.');
    }
}
