<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\PublicKeyAuthenticationFailed;
use PDF\Reading\Security\PublicKeyAuthenticationLimits;
use PDF\Reading\Security\PublicKeyReadPermissions;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\UnsupportedPdfFeature;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalEncryptionKey;
use PDF\Security\HistoricalObjectKeyDerivation;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\PublicKeyRecipient;
use PDF\Security\PublicKeySubFilter;
use PDF\Security\Rc4Cipher;
use PDF\Security\RecipientDecrypter;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic CMS capabilities isolate parser/access contracts; the Python oracle uses actual independent CMS. */
final class ReadingPublicKeyAuthenticationTest extends TestCase
{
    private const SEED = 'public fixture seed!';

    public function testFirstMatchingEnvelopeDefinesRightsAndAllOriginalPacketsDefineTheKey(): void
    {
        $packets = ['unmatched', 'matched', 'later'];
        $decrypter = $this->decrypter([
            'matched' => self::SEED . pack('N', 0xfffff0c0),
            'later' => self::SEED . pack('N', 0xfffffffe),
        ]);
        $document = $this->read($this->legacy($packets), $decrypter, $this->encryptedTitle($packets));
        $permissions = $document->permissions();
        self::assertInstanceOf(PublicKeyReadPermissions::class, $permissions);
        $access = array_values($permissions->filters)[0];
        self::assertSame(1, $access->recipientIndex);
        self::assertSame(0xfffff0c0, $access->permissions?->unsigned);
        self::assertSame(-3904, $permissions->effectiveMask());
        self::assertSame(['unmatched', 'matched'], $decrypter->calls);
        self::assertFalse(property_exists($permissions, 'revision'));
        self::assertFalse(property_exists($permissions, 'access'));
        self::assertFalse(property_exists($access, 'key'));
        $title = $document->catalog()->get('Title');
        self::assertInstanceOf(StringType::class, $title);
        self::assertSame('bounded public-key source', $title->value);
    }

    public function testChangingUnmatchedEnvelopeOrderChangesTheDerivedKey(): void
    {
        $decrypter = $this->decrypter(['match' => self::SEED . pack('N', 0xfffffffc)]);
        $title = $this->encryptedTitle(['a', 'b', 'match']);
        $document = $this->read($this->legacy(['b', 'a', 'match']), $decrypter, $title);
        self::assertNotSame('bounded public-key source', $document->catalog()->get('Title')->value);
    }

    public function testPermissionByteOrderIsAnExplicitApplicationChoiceAndLegacyPIgnored(): void
    {
        $payload = self::SEED . pack('V', 0xfffff0c0);
        $source = $this->pdf($this->legacy(['match']) . ' /P -4');
        $little = (new DocumentReader(authentication: new PublicKeyAuthentication($this->decrypter(['match' => $payload]), PublicKeyPermissionByteOrder::IsoLeastSignificantFirst)))->read($source);
        $big = (new DocumentReader(authentication: new PublicKeyAuthentication($this->decrypter(['match' => $payload]), PublicKeyPermissionByteOrder::MostSignificantFirst)))->read($source);
        self::assertSame(0xfffff0c0, array_values($little->permissions()->filters)[0]->permissions->unsigned);
        self::assertSame(0xc0f0ffff, array_values($big->permissions()->filters)[0]->permissions->unsigned);
        self::assertSame(-3904, $little->permissions()->effectiveMask());
    }

    public function testPublicKeyBitTwoGrantsAllRightsWithoutAStandardOwnerIdentity(): void
    {
        $document = $this->read($this->legacy(['match']), $this->decrypter(['match' => self::SEED . pack('N', 2)]));
        self::assertSame(-4, $document->permissions()->effectiveMask());
        self::assertTrue(array_values($document->permissions()->filters)[0]->permissions->ownerAccess());
    }

    /** @return iterable<string, array{int, int}> */
    public static function legacyPermissionWords(): iterable
    {
        // Expected common usage bits follow the four independent R2 rights.
        foreach (range(0, 15) as $rights) {
            $source = $rights << 2;
            $expected = $source;
            foreach ([4 => 2048, 8 => 1024, 16 => 512, 32 => 256] as $broad => $specific) {
                if (($source & $broad) !== 0) {
                    $expected |= $specific;
                }
            }
            yield 'R2 rights ' . $rights . ', reserved clear' => [$source, $expected];
            yield 'R2 rights ' . $rights . ', reserved set' => [$source | 0xf00, $expected];
        }
    }

    #[DataProvider('legacyPermissionWords')]
    public function testS3NormalizesOnlyR2UsageRightsAndPreservesAuthenticatedFacts(int $word, int $expected): void
    {
        $encryption = '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s3 /V 1 /Length 40 /Recipients [(match)]';
        $document = $this->read($encryption, $this->decrypter(['match' => self::SEED . pack('N', $word)]));
        $permissions = $document->permissions();
        self::assertInstanceOf(PublicKeyReadPermissions::class, $permissions);
        self::assertSame(PublicKeySubFilter::Pkcs7S3, $permissions->subFilter);
        self::assertSame($expected, $permissions->effectiveMask());
        $recipient = array_values($permissions->filters)[0];
        self::assertSame(PublicKeySubFilter::Pkcs7S3, $recipient->subFilter);
        self::assertSame($word, $recipient->permissions?->unsigned);
        self::assertSame($expected, $recipient->effectiveMask());

        $s4 = $this->read($this->legacy(['match']), $this->decrypter(['match' => self::SEED . pack('N', $word)]));
        self::assertSame($word, $s4->permissions()?->effectiveMask());
    }

    public function testS3AllPermissionsBitStillOverridesR2Restrictions(): void
    {
        $encryption = '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s3 /V 1 /Recipients [(match)]';
        $document = $this->read($encryption, $this->decrypter(['match' => self::SEED . pack('N', 2)]));
        self::assertSame(-4, $document->permissions()?->effectiveMask());
    }

    public function testDeferredAttachmentDoesNotAuthenticateUntilItIsAccessed(): void
    {
        $decrypter = $this->decrypter(['doc' => self::SEED . pack('N', 0xfffffffc)]);
        $document = $this->read($this->filters('EFOpen'), $decrypter, '', 'opaque ciphertext');
        self::assertSame(['doc'], $decrypter->calls);
        self::assertSame(-4, $document->permissions()->effectiveMask());
        try {
            $document->object(new ObjectIdentifier(new ObjectNumber(4)));
            self::fail('An inaccessible attachment must not expose ciphertext as plaintext.');
        } catch (PublicKeyAuthenticationFailed $error) {
            self::assertSame(['doc', 'attachment'], $decrypter->calls);
        }
    }

    public function testAnEfOpenFilterUsedByDefaultAuthenticatesAtDocumentOpen(): void
    {
        $encryption = str_replace('/AuthEvent /DocOpen', '/AuthEvent /EFOpen', $this->filters('EFOpen'));
        $this->expectException(PublicKeyAuthenticationFailed::class);
        $this->read($encryption, $this->decrypter([]));
    }

    public function testDocOpenFailureIsNotDeferred(): void
    {
        $this->expectException(PublicKeyAuthenticationFailed::class);
        $this->read($this->filters('DocOpen'), $this->decrypter(['doc' => self::SEED . pack('N', 0xfffffffc)]));
    }

    public function testDifferentExplicitStreamKeyAndTwentyByteScope(): void
    {
        $decrypter = $this->decrypter([
            'doc' => self::SEED . pack('N', 0xfffffffc),
            'attachment' => str_repeat('a', 20),
        ]);
        $key = new HistoricalEncryptionKey(substr(hash('sha1', str_repeat('a', 20) . 'attachment', true), 0, 16));
        $cipher = (new Rc4Cipher())->transform('independent attachment key', $key);
        $document = $this->read($this->filters('EFOpen'), $decrypter, '', $cipher, true);
        self::assertSame(['doc'], $decrypter->calls);
        $stream = $document->object(new ObjectIdentifier(new ObjectNumber(4)));
        self::assertInstanceOf(EncodedStream::class, $stream);
        self::assertSame('independent attachment key', $document->streamData($stream));
        self::assertSame($cipher, $stream->originalEncodedBytes());
        self::assertSame(['doc', 'attachment'], $decrypter->calls);
        self::assertSame($stream, $document->object(new ObjectIdentifier(new ObjectNumber(4))));
        self::assertSame(['doc', 'attachment'], $decrypter->calls);
    }

    /** @return iterable<string, array{int}> */
    public static function malformedPayloads(): iterable
    {
        foreach ([0, 19, 20, 23, 25, 32] as $length) {
            yield 'length ' . $length => [$length];
        }
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedMatchingDocumentPayloadDoesNotFallBackToLaterRecipients(int $length): void
    {
        $decrypter = $this->decrypter(['bad' => str_repeat('x', $length), 'good' => self::SEED . pack('N', 0xfffffffc)]);
        try {
            $this->read($this->legacy(['bad', 'good']), $decrypter);
            self::fail('A matching malformed CMS payload must fail.');
        } catch (ParseException $error) {
            self::assertSame(['bad'], $decrypter->calls);
        }
    }

    public function testNoneIsNotTreatedAsIdentity(): void
    {
        $this->expectException(UnsupportedPdfFeature::class);
        $this->read(str_replace('/CFM /V2', '/CFM /None', $this->filters('EFOpen')), $this->decrypter([]));
    }

    public function testStandardIdentityCannotBeRedefinedBySource(): void
    {
        $encryption = '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s5 /V 4 /CF << /Identity 9 >> /StmF /Identity /StrF /Identity';
        $decrypter = $this->decrypter([]);
        $this->read($encryption, $decrypter);
        self::assertSame([], $decrypter->calls);
    }

    public function testCredentialAttemptsAreCumulativeAndResetForEachDocument(): void
    {
        $decrypter = $this->decrypter(['match' => self::SEED . pack('N', 0xfffffffc)]);
        $reader = new DocumentReader(authentication: new PublicKeyAuthentication($decrypter, PublicKeyPermissionByteOrder::MostSignificantFirst, new PublicKeyAuthenticationLimits(maxCredentialAttempts: 1)));
        $reader->read($this->pdf($this->legacy(['match'])));
        $reader->read($this->pdf($this->legacy(['match'])));
        self::assertCount(2, $decrypter->calls);
        $this->expectException(ParseException::class);
        $reader->read($this->pdf($this->legacy(['skip', 'match'])));
    }

    public function testRecipientCountsAcrossFiltersAreBoundedBeforeAuthorization(): void
    {
        $decrypter = $this->decrypter(['doc' => self::SEED . pack('N', 0xfffffffc)]);
        $reader = new DocumentReader(authentication: new PublicKeyAuthentication($decrypter, PublicKeyPermissionByteOrder::MostSignificantFirst, new PublicKeyAuthenticationLimits(maxRecipients: 1)));
        $this->expectException(ParseException::class);
        $reader->read($this->pdf($this->filters('EFOpen')));
    }

    public function testCumulativeRecipientBytesAreBounded(): void
    {
        $reader = new DocumentReader(authentication: new PublicKeyAuthentication($this->decrypter([]), PublicKeyPermissionByteOrder::MostSignificantFirst, new PublicKeyAuthenticationLimits(maxTotalRecipientBytes: 7)));
        $this->expectException(ParseException::class);
        $reader->read($this->pdf($this->legacy(['abcd', 'efgh'])));
    }

    public function testRequiredMacCannotBeBypassedByLegacyPublicKeyAuthentication(): void
    {
        $reader = new DocumentReader(authentication: new PublicKeyAuthentication($this->decrypter(['match' => self::SEED . pack('N', 0xfffffffc)]), PublicKeyPermissionByteOrder::MostSignificantFirst), sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac);
        $this->expectException(ParseException::class);
        $reader->read($this->pdf($this->legacy(['match'])));
    }

    public function testUnselectedDocumentDefaultFilterCannotHideRequiredMacBehindEfOpen(): void
    {
        $encryption = '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s5 /V 5'
            . ' /CF << /DefaultCryptFilter << /CFM /AESV3 /Length 256 /AuthEvent /EFOpen /Recipients [(default)] >> >>'
            . ' /StrF /Identity /StmF /Identity';
        $decrypter = $this->decrypter(['default' => self::SEED . pack('N', 0xffffeffc)]);
        try {
            $this->read($encryption, $decrypter);
            self::fail('Authenticated required MAC must reject stripped markers before catalog access.');
        } catch (ParseException $error) {
            self::assertSame(['default'], $decrypter->calls);
            self::assertStringContainsString('KDFSalt', $error->getMessage());
        }
    }

    public function testExplicitCryptCannotReuseADocumentScopeEnvelope(): void
    {
        $reader = new DocumentReader(authentication: new PublicKeyAuthentication($this->decrypter(['doc' => self::SEED . pack('N', 0xfffffffc)]), PublicKeyPermissionByteOrder::MostSignificantFirst));
        // Preserve every physical offset when selecting another filter.
        $source = $this->pdf($this->filters('EFOpen'), '', 'ciphertext', true);
        $encryption = str_replace('/Name /Attachment', '/Name /Doc       ', $source->bytes);
        self::assertSame(strlen($source->bytes), strlen($encryption));
        $document = $reader->read(new FileBytes($encryption));
        $this->expectException(ParseException::class);
        $document->object(new ObjectIdentifier(new ObjectNumber(4)));
    }

    public function testBackendExceptionCannotExposeCredentialsOrOriginalCause(): void
    {
        $decrypter = new class () implements RecipientDecrypter {
            public function decrypt(PublicKeyRecipient $recipient): ?string
            {
                throw new \RuntimeException('SYNTHETIC_PRIVATE_SECRET');
            }
        };
        try {
            $this->read($this->legacy(['match']), $decrypter);
            self::fail('Backend failure must abort authentication.');
        } catch (ParseException $error) {
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE_SECRET', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function testPermissionsAreTheIntersectionOfDistinctDocumentFilters(): void
    {
        $encryption = '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s5 /V 4'
            . ' /CF << /Strings << /CFM /V2 /Length 128 /Recipients [(strings)] >>'
            . ' /Streams << /CFM /V2 /Length 128 /Recipients [(streams)] >> >>'
            . ' /StrF /Strings /StmF /Streams';
        $document = $this->read($encryption, $this->decrypter([
            'strings' => self::SEED . pack('N', 16),
            'streams' => str_repeat('s', 20) . pack('N', 4),
        ]));
        self::assertSame(0, $document->permissions()->effectiveMask());
        self::assertCount(2, $document->permissions()->filters);
    }

    /** @param array<string, string> $payloads */
    private function decrypter(array $payloads): RecipientDecrypter
    {
        return new class ($payloads) implements RecipientDecrypter {
            /** @var list<string> */
            public array $calls = [];

            /** @param array<string, string> $payloads */
            public function __construct(private readonly array $payloads)
            {
            }

            public function decrypt(PublicKeyRecipient $recipient): ?string
            {
                $this->calls[] = $recipient->value->value;
                return $this->payloads[$recipient->value->value] ?? null;
            }
        };
    }

    /** @param list<string> $packets */
    private function legacy(array $packets): string
    {
        return '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s4 /V 2 /Length 128 /Recipients ['
            . implode(' ', array_map(static fn (string $packet): string => '<' . bin2hex($packet) . '>', $packets)) . ']';
    }

    private function filters(string $event): string
    {
        return '/Filter /Adobe.PubSec /SubFilter /adbe.pkcs7.s5 /V 4 /Length 128'
            . ' /CF << /Doc << /CFM /V2 /Length 128 /AuthEvent /DocOpen /Recipients [(doc)] >>'
            . ' /Attachment << /CFM /V2 /Length 128 /AuthEvent /' . $event . ' /Recipients (attachment) >> >>'
            . ' /StmF /Doc /StrF /Doc /EFF /Attachment';
    }

    /** @param list<string> $packets */
    private function encryptedTitle(array $packets): string
    {
        $fileKey = new HistoricalEncryptionKey(substr(hash('sha1', self::SEED . implode('', $packets), true), 0, 16));
        $objectKey = (new HistoricalObjectKeyDerivation())->derive($fileKey, new ObjectIdentifier(new ObjectNumber(1)), HistoricalDataCipher::Rc4);
        return (new Rc4Cipher())->transform('bounded public-key source', $objectKey);
    }

    private function read(string $encryption, RecipientDecrypter $decrypter, string $title = '', ?string $attachment = null, bool $explicit = false): ReadDocument
    {
        return (new DocumentReader(authentication: new PublicKeyAuthentication($decrypter, PublicKeyPermissionByteOrder::MostSignificantFirst)))->read($this->pdf($encryption, $title, $attachment, $explicit));
    }

    private function pdf(string $encryption, string $title = '', ?string $attachment = null, bool $explicit = false): FileBytes
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R' . ($title === '' ? '' : ' /Title <' . bin2hex($title) . '>') . ' >>',
            '<< /Type /Pages /Kids [] /Count 0 >>',
            '<< ' . $encryption . ' >>',
        ];
        if ($attachment !== null) {
            $objects[] = '<< /Type /EmbeddedFile /Length ' . strlen($attachment)
                . ($explicit ? ' /Filter /Crypt /DecodeParms << /Name /Attachment >>' : '')
                . " >>\nstream\n" . $attachment . "\nendstream";
        }
        $bytes = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($bytes);
            $bytes .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        $bytes .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R /Encrypt 3 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return new FileBytes($bytes);
    }
}
