<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** A reference in the source document; resolution belongs to that document. */
final readonly class ObjectReference implements BasicType
{
    use SerializesPdf;

    public function __construct(public ObjectIdentifier $identifier)
    {
    }

    public function toPdf(SerializationContext $context): string
    {
        throw new \LogicException('External references require explicit import before serialization.');
    }
}
