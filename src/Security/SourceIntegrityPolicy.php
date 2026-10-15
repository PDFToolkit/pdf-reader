<?php

declare(strict_types=1);

namespace PDF\Reading\Security;

/** Applications expecting authenticated input can reject clear or stripped sources as well. */
enum SourceIntegrityPolicy
{
    case AcceptUnprotected;
    case RequirePdfMac;
}
