<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

/** ISO/TS 32004 Table 6; this status does not assert signature validity or PKI trust. */
enum PdfMacLocation: string
{
    case Standalone = 'Standalone';
    case AttachedToSignature = 'AttachedToSig';
}
