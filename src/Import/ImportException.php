<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

/** Fixed diagnostics never include source bytes, keys, credentials or file names. */
final class ImportException extends \RuntimeException
{
}
