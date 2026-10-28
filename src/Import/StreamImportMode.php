<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

/** Metadata XML is always decoded so the writer validates actual XML source bytes. */
enum StreamImportMode
{
    case PreserveEncoded;
    case Decode;
}
