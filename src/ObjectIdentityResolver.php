<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** @internal Bounded reference identity checks without recursively loading the selected stream. */
interface ObjectIdentityResolver extends ObjectResolver
{
    public function refersTo(ObjectReference $reference, ObjectIdentifier $identifier): bool;
}
