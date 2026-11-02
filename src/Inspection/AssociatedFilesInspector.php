<?php

declare(strict_types=1);

namespace PDF\Reading\Inspection;

use PDF\Reading\EncodedStream;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Describe explicit associations and the embedded-files name tree; never read stream contents. */
final class AssociatedFilesInspector
{
    /** @var list<array<string, mixed>> */
    private array $files = [];
    /** @var array<int, true> */
    private array $nameTrees = [];

    public function __construct(private readonly InspectionAccess $access, private readonly PdfValueInspector $values)
    {
    }

    /** @param array<string, mixed> $owner */
    public function associated(DictionaryType $dictionary, array $owner): void
    {
        foreach ($this->access->items($dictionary->get('AF')) as $source) {
            if (!$this->file($source, $owner)) {
                break;
            }
        }
    }

    public function embedded(?BasicType $source, int $depth = 1): void
    {
        if ($source === null || !$this->access->budget->depth($depth) || !$this->access->budget->reserve('values')) {
            return;
        }
        $dictionary = $this->access->dictionary($source);
        if ($dictionary === null) {
            $this->access->budget->diagnostic('invalid-embedded-files-tree', 'An embedded-files name-tree node must be a dictionary.');
            return;
        }
        $id = spl_object_id($dictionary);
        if (isset($this->nameTrees[$id])) {
            $this->access->budget->diagnostic('repeated-embedded-files-tree', 'The embedded-files name tree contains a cycle or repeated node.');
            return;
        }
        $this->nameTrees[$id] = true;
        $names = $this->access->resolved($dictionary->get('Names'));
        if ($names instanceof ArrayType) {
            if (count($names) % 2 !== 0) {
                $this->access->budget->diagnostic('invalid-embedded-files-names', 'Embedded-file names require key/value pairs.');
            }
            for ($index = 1; $index < count($names); $index += 2) {
                if (!$this->file($names->items[$index], ['kind' => 'embedded-files', 'id' => 'embedded-files', 'reference' => $this->access->reference($source)])) {
                    return;
                }
            }
        }
        foreach ($this->access->items($dictionary->get('Kids')) as $child) {
            if (!$this->access->budget->reserve('values')) {
                break;
            }
            $this->embedded($child, $depth + 1);
        }
    }

    /** @param array<string, mixed> $owner */
    private function file(BasicType $source, array $owner): bool
    {
        if (!$this->access->budget->reserve('associations') || !$this->access->budget->reserve('bytes', 512)) {
            return false;
        }
        $value = $this->access->resolved($source);
        if (!$value instanceof DictionaryType && !$value instanceof StringType) {
            $this->access->budget->diagnostic('invalid-file-specification', 'An associated file must resolve to a file specification.', reference: $this->access->reference($source));
            return true;
        }
        $filename = $this->access->text($value instanceof DictionaryType ? ($value->get('UF') ?? $value->get('F')) : $value);
        $relationship = $value instanceof DictionaryType ? $this->access->resolved($value->get('AFRelationship')) : null;
        $streams = [];
        $embedded = $value instanceof DictionaryType ? $this->access->dictionary($value->get('EF')) : null;
        foreach ($embedded?->entries() ?? [] as $entry) {
            if (!$this->access->budget->reserve('values') || !$this->access->budget->reserve('bytes', 256)) {
                break;
            }
            $stream = $this->access->resolved($entry->value);
            if (!$stream instanceof EncodedStream) {
                $this->access->budget->diagnostic('invalid-embedded-stream', 'An embedded-file stream entry does not resolve to a stream.', reference: $this->access->reference($entry->value));
                continue;
            }
            $streams[] = ['key' => $this->values->inspect($entry->key), 'reference' => $this->access->reference($entry->value), 'properties' => $this->values->inspect($stream->dictionary()), 'encodedByteLength' => strlen($stream->encodedBytes())];
        }
        // Relationship is a display label only; the typed property keeps exact name bytes.
        $label = $relationship instanceof NameType && preg_match('/\A[A-Za-z]+\z/D', $relationship->value) === 1 && strlen($relationship->value) <= 64 ? $relationship->value : null;
        $this->files[] = ['id' => 'file-' . (count($this->files) + 1), 'owner' => $owner, 'reference' => $this->access->reference($source), 'filename' => $filename, 'relationship' => $label, 'properties' => $this->values->inspect($value), 'embeddedStreams' => $streams];
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function files(): array
    {
        return $this->files;
    }
}
