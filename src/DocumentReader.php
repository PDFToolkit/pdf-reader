<?php

declare(strict_types=1);

namespace PDF\Reading;

use PDF\Reading\CrossReference\Index;
use PDF\Reading\Security\DocumentAuthentication;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NameType;

/** Read PDF 1.0–2.0 syntax; source access and upload policy belong to the application. */
final readonly class DocumentReader
{
    public function __construct(private ReaderLimits $limits = new ReaderLimits(), private ?DocumentAuthentication $authentication = null, private SourceIntegrityPolicy $sourceIntegrity = SourceIntegrityPolicy::AcceptUnprotected)
    {
    }

    public function read(FileBytes $source): ReadDocument
    {
        $length = strlen($source->bytes);
        if ($length > $this->limits->maxFileBytes) {
            throw new ParseException('The PDF source exceeds the file-byte limit.');
        }
        if (!preg_match('/\A%PDF-(1\.[0-7]|2\.0)(?:\r\n|\r|\n)/', $source->bytes, $header)) {
            throw new UnsupportedPdfFeature('A PDF 1.0–1.7 or 2.0 header is required at the beginning of the source.');
        }
        // Bound the footer search independently of the potentially much larger source.
        $tail = substr($source->bytes, -min($length, $this->limits->maxTokenBytes));
        if (!preg_match('/(?:\A|[\r\n])startxref[\x00\x09\x0a\x0c\x0d\x20]+([0-9]+)[\x00\x09\x0a\x0c\x0d\x20]+%%EOF[\x00\x09\x0a\x0c\x0d\x20]*\z/', $tail, $footer)) {
            throw new ParseException('The final startxref and EOF markers are missing or outside the footer search limit.');
        }
        $budget = new ReadBudget($this->limits);
        $offset = (new SyntaxParser(new ByteCursor($footer[1]), $this->limits, $budget))->unsignedInteger();
        $decoder = new StreamDecoder($this->limits);
        $strings = new SourceStringSpans($budget);
        $reader = new CrossReferenceReader($source, $this->limits, $budget, $decoder, $strings);
        $sections = [];
        $seen = [];
        $trailerEntries = [];
        $newerSize = null;
        do {
            if (isset($seen[$offset]) || count($sections) >= $this->limits->maxRevisions) {
                throw new ParseException('The cross-reference revision chain contains a cycle or exceeds its limit.', $offset);
            }
            $seen[$offset] = true;
            $section = $reader->read($offset);
            $size = (new DictionaryAccess($section->trailer))->integer('Size', 1, $this->limits->maxObjects);
            if ($newerSize !== null && $size > $newerSize) {
                throw new ParseException('Trailer Size cannot shrink across incremental revisions.', $offset);
            }
            $newerSize = $size;
            $sections[] = $section;
            foreach ($section->trailer->entries() as $entry) {
                $trailerEntries[$entry->key->value] ??= $entry;
            }
            $offset = $section->previous?->value;
        } while ($offset !== null);
        /** @var list<Entry> $entries */
        $entries = array_values($trailerEntries);
        $fileHeader = new FileHeader($header[1]);
        $trailer = new DictionaryType(...$entries);
        $index = new Index(...$sections);
        $physical = new SourceRevision($source, $sections[0]->trailer, $strings, $budget);
        $document = new ReadDocument($source, $fileHeader, $trailer, $index, $this->limits, $budget, $decoder, physicalRevision: $physical, sourceStrings: $strings);
        // A null dictionary value is equivalent to absence, including a reference that resolves to null.
        // The completed index is required here; resolution keeps the same document's cumulative budgets.
        $encryption = $document->trailer()->get('Encrypt');
        $encryptionObject = $encryption instanceof ObjectReference ? $encryption->identifier : null;
        if ($encryption !== null) {
            $encryption = $document->resolve($encryption);
            if (!$encryption instanceof NullType) {
                if (!$encryption instanceof DictionaryType) {
                    throw new ParseException('An Encrypt entry must resolve to an encryption dictionary.', $sections[0]->offset->value);
                }
                if ($this->authentication === null) {
                    throw new EncryptedPdfException($sections[0]->offset->value);
                }
                $decryption = $this->authentication->authenticate($document, $encryption, $encryptionObject);
                $document = new ReadDocument($source, $fileHeader, $trailer, $index, $this->limits, $budget, $decoder, $decryption, $physical);
            }
        }
        if ($document->permissions() === null && $trailer->has('AuthCode')) {
            throw new UnsupportedPdfFeature('A source AuthCode requires authenticated encryption before content interpretation.');
        }
        if ($this->sourceIntegrity === SourceIntegrityPolicy::RequirePdfMac && $document->mac() === null) {
            throw new ParseException('The selected source-integrity policy requires a verified PDF MAC.');
        }
        $catalog = $document->catalog();
        if ($document->mac() !== null && $fileHeader->version->value !== '2.0') {
            $version = $catalog->get('Version');
            $version = $version === null ? null : $document->resolve($version);
            if (!$version instanceof NameType || $version->value !== '2.0') {
                throw new ParseException('A PDF MAC source requires PDF version 2.0.');
            }
        }
        return $document;
    }
}
