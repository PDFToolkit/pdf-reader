<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ParseException;

final class PublicKeyAuthenticationFailed extends ParseException
{
    public function __construct()
    {
        parent::__construct('The supplied public-key identities did not authenticate this PDF crypt filter.');
    }
}
