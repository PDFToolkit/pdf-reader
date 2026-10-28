<?php

declare(strict_types=1);

namespace PDF\Reading\Import;

use PDF\Reading\ReadDocument;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** @internal Copying content grants neither signature-edit permission nor source encryption authority. */
final readonly class ImportGuard
{
    private ?BasicType $encryption;

    public function __construct(private ReadDocument $document)
    {
        $this->encryption = $this->resolved($document->trailer()->get('Encrypt'));
    }

    public function dictionary(DictionaryType $dictionary): void
    {
        $type = $this->resolved($dictionary->get('Type'));
        $filter = $this->resolved($dictionary->get('Filter'));
        $fieldType = $this->resolved($dictionary->get('FT'));
        if ($dictionary === $this->encryption
            || $this->resolved($dictionary->get('Encrypt')) !== null
            || $this->resolved($dictionary->get('AuthCode')) !== null
            || ($filter instanceof NameType && in_array($filter->value, ['Standard', 'Adobe.PubSec'], true))
        ) {
            throw new ImportException('Source encryption and authentication dictionaries cannot be imported.');
        }
        if (($type instanceof NameType && in_array($type->value, ['Sig', 'DocTimeStamp'], true))
            || ($dictionary->has('Contents') && ($dictionary->has('ByteRange') || $dictionary->has('SubFilter')))
            || ($fieldType instanceof NameType && $fieldType->value === 'Sig' && $this->resolved($dictionary->get('V')) !== null)
        ) {
            throw new ImportException('Active source signatures cannot be imported; select an unsigned content subgraph explicitly.');
        }
        if ($type instanceof NameType && in_array($type->value, ['ObjStm', 'XRef'], true)) {
            throw new ImportException('Source transport objects cannot be copied; import their resolved logical objects instead.');
        }
    }

    private function resolved(?BasicType $value): ?BasicType
    {
        $value = $value === null ? null : $this->document->resolve($value);
        return $value instanceof NullType ? null : $value;
    }
}
