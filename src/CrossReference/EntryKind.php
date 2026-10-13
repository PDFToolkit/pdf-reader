<?php

declare(strict_types=1);

namespace PDF\Reading\CrossReference;

/** Unknown stream entry types designate null objects (7.5.8.3). */
enum EntryKind
{
    case Free;
    case Uncompressed;
    case Compressed;
    case Unavailable;
}
