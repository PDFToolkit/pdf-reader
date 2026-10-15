<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReaderLimits;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingRevisionHistoryTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function histories(): iterable
    {
        yield 'table' => ['classic', 1];
        yield 'object stream' => ['object-streams', 1];
        yield 'incremental' => ['incremental', 2];
        yield 'deletion' => ['deleted-object', 2];
        yield 'reused generation' => ['reused-generation', 3];
    }

    #[DataProvider('histories')]
    public function testExistingIndependentFixturesKeepExactPhysicalPrefixes(string $name, int $count): void
    {
        $source = $this->fixture($name);
        $history = (new DocumentReader())->readRevisionHistory($source);
        self::assertSame($source, $history->source);
        self::assertCount($count, $history);
        self::assertSame($source->bytes, $history->latest()->source()->bytes);
        $previous = null;
        foreach ($history as $ordinal => $revision) {
            self::assertSame($ordinal, $revision->ordinal);
            self::assertSame('%%EOF', substr($source->bytes, $revision->boundary->eofOffset, 5));
            self::assertTrue($revision->acceptsByteLength($revision->boundary->endOffset));
            self::assertFalse($revision->acceptsByteLength($revision->boundary->eofMarkerEnd - 1));
            self::assertFalse($revision->acceptsByteLength($revision->boundary->maximumEndOffset + 1));
            self::assertSame(substr($source->bytes, 0, $revision->boundary->endOffset), $revision->source()->bytes);
            self::assertSame($previous, $revision->crossReference->section->previous?->value);
            $previous = $revision->crossReference->section->offset->value;
            self::assertNull($revision->rawDocument()->permissions());
            self::assertNull($revision->rawDocument()->mac());
            foreach ($revision->rawDocument()->crossReferences()->entries() as $entry) {
                self::assertSame($entry, $revision->rawDocument()->crossReferences()->entry($entry->number));
            }
        }
    }

    public function testEofWhitespaceIsAnIntervalAndIsNeverNormalized(): void
    {
        $source = new FileBytes($this->fixture('classic')->bytes . " \t\r\n");
        $revision = (new DocumentReader())->readRevisionHistory($source)->latest();
        self::assertSame(strlen($source->bytes), $revision->boundary->maximumEndOffset);
        self::assertSame($source->bytes, $revision->source(strlen($source->bytes))->bytes);
        self::assertSame('%%EOF', substr($revision->source($revision->boundary->eofMarkerEnd)->bytes, -5));
        $this->expectException(ParseException::class);
        $revision->source(strlen($source->bytes) + 1);
    }

    public function testOrdinaryObjectSpansPreserveGenerationAndCompressedMembersHaveNone(): void
    {
        $history = (new DocumentReader())->readRevisionHistory($this->fixture('reused-generation'));
        $views = iterator_to_array($history);
        $old = new ObjectIdentifier(new ObjectNumber(9));
        $current = new ObjectIdentifier(new ObjectNumber(9), new GenerationNumber(1));
        self::assertNotNull($views[0]->rawDocument()->objectSpan($old));
        self::assertNull($views[1]->rawDocument()->objectSpan($old));
        self::assertNull($views[2]->rawDocument()->objectSpan($old));
        $span = $views[2]->rawDocument()->objectSpan($current);
        self::assertNotNull($span);
        self::assertStringStartsWith('9 1 obj', substr($history->source->bytes, $span->start, $span->end - $span->start));
        self::assertStringEndsWith('endobj', substr($history->source->bytes, $span->start, $span->end - $span->start));
        $compressed = (new DocumentReader())->readRevisionHistory($this->fixture('object-streams'))->latest()->rawDocument();
        self::assertNull($compressed->objectSpan(new ObjectIdentifier(new ObjectNumber(4))));
    }

    public function testIndexedObjectsMayFollowThePrimaryXrefStreamBeforeItsActualFooter(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/revision-history/trailing-xref.pdf');
        self::assertNotFalse($bytes);
        $history = (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
        self::assertCount(1, $history);
        $revision = $history->latest();
        self::assertTrue($revision->crossReference->stream);
        self::assertSame($bytes, $revision->source()->bytes);
        self::assertSame('startxref', substr($bytes, $revision->boundary->footerOffset, 9));
        $streamId = new ObjectIdentifier(new ObjectNumber(5));
        $span = $revision->rawDocument()->objectSpan($streamId);
        self::assertNotNull($span);
        self::assertGreaterThan($revision->crossReference->endOffset, $span->start);
        self::assertLessThan($revision->boundary->footerOffset, $span->end);
        $stream = $revision->rawDocument()->object($streamId);
        self::assertInstanceOf(\PDF\Reading\EncodedStream::class, $stream);
        self::assertSame("Fake startxref 0 %%EOF endobj markers are only stream data.\n", $revision->rawDocument()->streamData($stream));
    }

    public function testRawEncryptedHistoryDoesNotAuthenticateOrWeakenTheSeparateReadPolicy(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf-mac/pyhanko-attached-1.pdf');
        self::assertNotFalse($bytes);
        $reader = new DocumentReader(sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac);
        $history = $reader->readRevisionHistory(new FileBytes($bytes));
        self::assertNull($history->latest()->rawDocument()->permissions());
        self::assertNull($history->latest()->rawDocument()->mac());
        $this->expectException(\PDF\Reading\EncryptedPdfException::class);
        $reader->read($history->latest()->source());
    }

    public function testAuthenticatedSignatureContentsKeepTheirOriginalPhysicalSpan(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf-mac/pyhanko-attached-1.pdf');
        self::assertNotFalse($bytes);
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read(new FileBytes($bytes));
        $found = 0;
        foreach ($document->crossReferences()->entries() as $entry) {
            if ($entry->kind !== \PDF\Reading\CrossReference\EntryKind::Uncompressed) {
                continue;
            }
            $value = $document->object(new ObjectIdentifier(new ObjectNumber($entry->number), new GenerationNumber($entry->generation())));
            if (!$value instanceof DictionaryType || !$value->has('ByteRange')) {
                continue;
            }
            $contents = $value->get('Contents');
            self::assertInstanceOf(StringType::class, $contents);
            $span = $document->sourceRevision()->span($contents);
            self::assertNotNull($span);
            self::assertStringStartsWith('<', substr($bytes, $span->start, $span->end - $span->start));
            ++$found;
        }
        self::assertSame(1, $found);
    }

    public function testRepeatedSourceCopiesShareTheHistoryRetentionBudget(): void
    {
        $source = new FileBytes($this->fixture('classic')->bytes . ' ');
        $revision = (new DocumentReader(new ReaderLimits(maxRetainedBytes: 9000)))->readRevisionHistory($source)->latest();
        self::assertSame($source->bytes, $revision->source(strlen($source->bytes))->bytes);
        $this->expectException(ParseException::class);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $revision->source(strlen($source->bytes));
        }
    }

    public function testRevisionCountLimitAppliesToTheCompleteHistory(): void
    {
        $this->expectException(ParseException::class);
        (new DocumentReader(new ReaderLimits(maxRevisions: 1)))->readRevisionHistory($this->fixture('incremental'));
    }

    public function testAnUnindexedObjectCannotBeHiddenBeforeTheXref(): void
    {
        $source = $this->minimal("99 0 obj (unindexed) endobj\n");
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->catalog());
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->readRevisionHistory($source);
    }

    public function testAnExtraFooterCannotClaimTheEarlierXrefAsANewPhysicalRevision(): void
    {
        $source = $this->fixture('classic');
        $forged = new FileBytes($source->bytes . "startxref\n1674\n%%EOF\n");
        $this->expectException(ParseException::class);
        (new DocumentReader())->readRevisionHistory($forged);
    }

    public function testASectionOffsetCannotSkipAnEarlierComment(): void
    {
        $source = $this->minimal();
        $bytes = preg_replace_callback('/startxref\n([0-9]+)/', static fn (array $m): string => "startxref\n" . ((int) $m[1] - 1), $source->bytes);
        self::assertIsString($bytes);
        $this->expectException(ParseException::class);
        (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
    }

    public function testAnObjectHeaderInsideACommentCannotClaimPhysicalOwnership(): void
    {
        $source = $this->minimal(header: "%PDF-1.7\n% ");
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read($source)->catalog());
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->readRevisionHistory($source);
    }

    public function testOrdinaryReadingRetainsTheActualHeaderSpanWhenItsOffsetSkipsWhitespace(): void
    {
        $source = new FileBytes(str_replace('0000000009 00000 n', '0000000008 00000 n', $this->minimal()->bytes));
        $document = (new DocumentReader())->read($source);
        $span = $document->objectSpan(new ObjectIdentifier(new ObjectNumber(1)));
        self::assertNotNull($span);
        self::assertSame(9, $span->start);
        self::assertSame('1 0 obj', substr($source->bytes, $span->start, 7));
        $this->expectException(ParseException::class);
        (new DocumentReader())->readRevisionHistory($source);
    }

    public function testAnObjectHeaderInsideAStringCannotClaimAnotherPhysicalObject(): void
    {
        $source = $this->minimal();
        $old = '<< /Type /Catalog /Pages 2 0 R >>';
        $replacement = '<< /Type /Catalog /Pages 2 0 R /Fake (3 0 obj << /Private true >> endobj) >>';
        $bytes = str_replace($old, $replacement, $source->bytes);
        $second = strpos($bytes, '2 0 obj');
        $third = strpos($bytes, '3 0 obj');
        $xref = strpos($bytes, "xref\n");
        self::assertIsInt($second);
        self::assertIsInt($third);
        self::assertIsInt($xref);
        $bytes = substr($bytes, 0, $xref) . "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n"
            . sprintf("%010d 00000 n \n%010d 00000 n \n", $second, $third)
            . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        $this->expectException(ParseException::class);
        (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
    }

    public function testAnOlderXrefCannotReachForwardIntoALaterRevision(): void
    {
        $source = $this->minimal()->bytes;
        $prior = strpos($source, "xref\n");
        self::assertIsInt($prior);
        $replacement = strlen($source);
        $bytes = $source . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R /HistoryMarker (latest) >>\nendobj\n";
        $xref = strlen($bytes);
        $bytes .= "xref\n1 1\n" . sprintf("%010d 00000 n \n", $replacement)
            . "trailer\n<< /Size 3 /Root 1 0 R /Prev " . $prior . " >>\nstartxref\n" . $xref . "\n%%EOF\n";
        $bytes = str_replace('0000000009 00000 n', sprintf('%010d 00000 n', $replacement), $bytes);
        self::assertInstanceOf(DictionaryType::class, (new DocumentReader())->read(new FileBytes($bytes))->catalog());
        $this->expectException(ParseException::class);
        (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
    }

    public function testAForwardPrevCannotPretendToBeAChronologicalHistory(): void
    {
        $bytes = str_replace('/Prev 1674', '/Prev 2126', $this->fixture('incremental')->bytes);
        $this->expectException(UnsupportedPdfFeature::class);
        (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
    }

    public function testCrossReferenceBudgetIsSharedByAllRevisionViews(): void
    {
        $reader = new DocumentReader(new ReaderLimits(maxCrossReferenceEntries: 10));
        self::assertCount(1, $reader->readRevisionHistory($this->fixture('classic')));
        $this->expectException(ParseException::class);
        $reader->readRevisionHistory($this->fixture('incremental'));
    }

    private function fixture(string $name): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf/' . $name . '.pdf');
        self::assertNotFalse($bytes);
        return new FileBytes($bytes);
    }

    private function minimal(string $unindexed = '', string $header = "%PDF-1.7\n"): FileBytes
    {
        $bytes = $header;
        $offsets = [];
        foreach ([1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [] /Count 0 >>'] as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $value . "\nendobj\n";
        }
        $bytes .= $unindexed;
        $xref = strlen($bytes);
        $bytes .= "xref\n0 3\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        return new FileBytes($bytes . "trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
    }
}
