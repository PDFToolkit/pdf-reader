<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;

/** @internal A shell stays private to the import until its single initialization has succeeded. */
final class ImportedDictionary extends DictionaryType
{
    private bool $sealed = false;

    public function __construct()
    {
    }

    public function seal(Entry ...$entries): void
    {
        if ($this->sealed) {
            throw new \LogicException('An imported dictionary is already sealed.');
        }
        $this->sealed = true;
        parent::__construct(...$entries);
    }

    public function entries(): iterable
    {
        if (!$this->sealed) {
            throw new \LogicException('An imported dictionary is not sealed.');
        }
        yield from parent::entries();
    }
}
