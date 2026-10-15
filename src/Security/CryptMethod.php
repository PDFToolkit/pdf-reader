<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

/** ISO 32000-1/2 crypt-filter method names, including ISO/TS 32003 AESV4. */
enum CryptMethod: string
{
    case Identity = 'None';
    case Rc4 = 'V2';
    case Aes128 = 'AESV2';
    case Aes256 = 'AESV3';
    case AesGcm = 'AESV4';
}
