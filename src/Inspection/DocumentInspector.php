<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Reading\ReadDocument;

/** Inspect parsed source objects without decoding streams or deriving replacement content. */
final readonly class DocumentInspector
{
    public function __construct(private InspectionLimits $limits = new InspectionLimits())
    {
    }

    public function inspect(ReadDocument $document): DocumentInspection
    {
        return (new InspectionSession($document, $this->limits))->inspect();
    }
}
