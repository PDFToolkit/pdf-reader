<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

use PDF\Reading\ReadDocument;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** Authentication examines a bounded raw graph before any encrypted content is interpreted. */
interface DocumentAuthentication
{
    public function authenticate(ReadDocument $raw, DictionaryType $encryption, ?ObjectIdentifier $encryptionObject): DocumentDecryption;
}
