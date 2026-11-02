<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Reading\EncodedStream;
use PDF\Reading\ObjectReference;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Preserve parsed types and source references, with no recursive reference expansion. */
final readonly class PdfValueInspector
{
    public function __construct(private InspectionAccess $access)
    {
    }

    public function inspect(?BasicType $value, int $depth = 1): InspectionValue
    {
        $budget = $this->access->budget;
        if (!$budget->depth($depth) || !$budget->reserve('values') || !$budget->reserve('bytes', 256)) {
            return new InspectionValue('truncated', ['reason' => 'inspection-limit']);
        }
        if ($value === null || $value instanceof NullType) {
            return new InspectionValue('null', ['value' => null]);
        }
        if ($value instanceof ObjectReference) {
            $queued = $this->access->queue($value);
            return new InspectionValue('reference', ['reference' => $this->access->reference($value), 'target' => $queued ? $this->access->identity($value) : null, 'status' => $queued ? 'queued' : 'omitted']);
        }
        if ($value instanceof NameType || $value instanceof StringType) {
            $bytes = $value->value;
            $length = strlen($bytes);
            $limit = min($length, $budget->limits->maxStringBytes);
            if (!$budget->reserve('bytes', $limit * 14)) {
                return new InspectionValue('truncated', ['reason' => 'inspection-bytes']);
            }
            $prefix = substr($bytes, 0, $limit);
            $truncated = $limit !== $length;
            if ($truncated) {
                $budget->limited('limit-string', 'A source byte string exceeds the inspection preview limit.');
            }
            if ($value instanceof NameType) {
                // Name bytes are not Unicode text. PDF #XX notation is injective and safe UTF-8.
                $preview = '/';
                for ($index = 0; $index < $limit; ++$index) {
                    $byte = ord($prefix[$index]);
                    $preview .= $byte < 33 || $byte > 126 || str_contains('()<>[]{}/%#', $prefix[$index]) ? sprintf('#%02X', $byte) : $prefix[$index];
                }
                return new InspectionValue('name', ['hex' => bin2hex($prefix), 'byteLength' => $length, 'preview' => $preview, 'truncated' => $truncated]);
            }
            $preview = !$truncated ? $this->access->decodeText($value) : null;
            return new InspectionValue('string', ['hex' => bin2hex($prefix), 'byteLength' => $length, 'preview' => $preview, 'truncated' => $truncated, 'syntax' => $value instanceof HexadecimalString ? 'hexadecimal' : 'literal']);
        }
        if ($value instanceof BooleanType) {
            return new InspectionValue('boolean', ['value' => $value->value]);
        }
        if ($value instanceof IntegerType) {
            return new InspectionValue('integer', ['value' => (string) $value->integer()]);
        }
        if ($value instanceof NumericType) {
            return new InspectionValue('real', ['value' => $value->value]);
        }
        if ($value instanceof EncodedStream) {
            return new InspectionValue('stream', ['dictionary' => $this->inspect($value->dictionary(), $depth + 1), 'encodedByteLength' => strlen($value->encodedBytes())]);
        }
        if ($value instanceof ArrayType) {
            $items = [];
            foreach ($value->items as $item) {
                $inspected = $this->inspect($item, $depth + 1);
                $items[] = $inspected;
                if ($inspected->type === 'truncated') {
                    break;
                }
            }
            return new InspectionValue('array', ['items' => $items, 'truncated' => count($items) !== count($value) || ($items !== [] && $items[array_key_last($items)]->type === 'truncated')]);
        }
        if ($value instanceof DictionaryType) {
            $entries = [];
            $truncated = false;
            foreach ($value->entries() as $entry) {
                $key = $this->inspect($entry->key, $depth + 1);
                $item = $this->inspect($entry->value, $depth + 1);
                $entries[] = ['key' => $key, 'value' => $item];
                if ($key->type === 'truncated' || $item->type === 'truncated') {
                    $truncated = true;
                    break;
                }
            }
            return new InspectionValue('dictionary', ['entries' => $entries, 'truncated' => $truncated]);
        }
        $budget->diagnostic('unsupported-value', 'This parsed value has no inspection representation.');
        return new InspectionValue('unsupported');
    }
}
