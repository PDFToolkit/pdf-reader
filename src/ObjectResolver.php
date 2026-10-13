<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Syntax\FileStructure\BasicType;

/** Resolve one document-scoped indirect reference, without walking its graph. */
interface ObjectResolver
{
    public function resolve(BasicType $value): BasicType;
}
