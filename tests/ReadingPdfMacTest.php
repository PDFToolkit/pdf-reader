<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncryptedPdfException;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Reading\ParseException;
use PDF\Reading\ReadDocument;
use PDF\Reading\Security\AuthenticationFailed;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\SourcePdfMacVerification;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PasswordAccess;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\DictionaryType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingPdfMacTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function independentFiles(): iterable
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/pdf-mac/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $file) {
            yield $file['file'] => [$file['file'], $file['location'], $file['sha256']];
        }
    }

    #[DataProvider('independentFiles')]
    public function testIndependentSourceIsAuthenticatedBeforeItsContentsBecomeReadable(string $filename, string $location, string $sha256): void
    {
        $source = $this->source($filename);
        self::assertSame($sha256, hash('sha256', $source->bytes));
        foreach (['user' => PasswordAccess::User, 'owner' => PasswordAccess::Owner] as $password => $access) {
            $document = $this->read($source, $password);
            self::assertSame($location, $document->mac()?->value);
            self::assertSame($access, $document->permissions()?->access);
            self::assertSame(-4100, $document->permissions()?->mask);
            $binary = $document->catalog()->get('FixtureBinary');
            self::assertInstanceOf(StringType::class, $binary);
            self::assertSame(implode('', array_map(chr(...), range(0, 255))), $binary->value);
            $compressed = $document->catalog()->get('FixtureCompressed');
            if ($compressed !== null) {
                $value = $document->resolve($compressed);
                self::assertInstanceOf(DictionaryType::class, $value);
                self::assertInstanceOf(StringType::class, $value->get('Value'));
                self::assertSame('compressed authenticated value', $value->get('Value')->value);
            }
            $pages = (new PageTreeReader($document))->read();
            self::assertCount(1, $pages);
            $contents = '';
            foreach ($pages as $page) {
                foreach ((new PageContentsReader($document))->read($page) as $stream) {
                    $contents .= $document->streamData($stream);
                }
            }
            self::assertSame("q 1 0 0 1 0 0 cm Q\n", $contents);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function tamperedFiles(): iterable
    {
        foreach (self::independentFiles() as [$file, $location]) {
            foreach (['source-comment', 'stale-append', 'salt-stripped', 'auth-code-stripped', 'both-stripped', 'permissions'] as $mutation) {
                yield $file . '/' . $mutation => [$file, $mutation];
            }
            yield $file . '/payload' => [$file, $location === 'AttachedToSig' ? 'nonzero-signature-padding' : 'mac-value'];
        }
    }

    #[DataProvider('tamperedFiles')]
    public function testTamperingIsRejectedAtReadBeforeAnyCatalogIsReturned(string $filename, string $mutation): void
    {
        $source = $this->source($filename)->bytes;
        $changed = match ($mutation) {
            'source-comment' => substr_replace($source, 'X', 10, 1),
            'stale-append' => $source . " \n",
            'salt-stripped' => str_replace('/KDFSalt', '/NoFSalt', $source),
            'auth-code-stripped' => str_replace('/AuthCode', '/NoThCode', $source),
            'both-stripped' => str_replace(['/KDFSalt', '/AuthCode'], ['/NoFSalt', '/NoThCode'], $source),
            'permissions' => str_replace('/P -4100', '/P -0004', $source),
            'mac-value' => preg_replace_callback('/\/MAC\s*<([0-9a-fA-F]+)>/', static fn (array $match): string => str_replace($match[1], substr($match[1], 0, -1) . ($match[1][-1] === '0' ? '1' : '0'), $match[0]), $source),
            'nonzero-signature-padding' => preg_replace('/(\/Contents\s*<[0-9a-fA-F]+)00>/', '${1}01>', $source),
            default => throw new \LogicException('Unknown test mutation.'),
        };
        self::assertNotNull($changed);
        self::assertNotSame($source, $changed);
        $this->expectException(ParseException::class);
        $this->read(new FileBytes($changed));
    }

    public function testIncorrectPasswordStillFailsWithoutAClaimOfMacValidation(): void
    {
        $this->expectException(AuthenticationFailed::class);
        $this->read($this->source('pyhanko-standalone-sha256.pdf'), 'incorrect');
    }

    public function testEncryptionCannotBeReadWithoutExplicitAuthentication(): void
    {
        $this->expectException(EncryptedPdfException::class);
        (new DocumentReader())->read($this->source('pyhanko-standalone-sha256.pdf'));
    }

    public function testMacByteBudgetIsEnforcedForBothLocations(): void
    {
        foreach (['pyhanko-standalone-sha256.pdf', 'pyhanko-attached-0.pdf'] as $file) {
            try {
                (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'), mac: new SourcePdfMacVerification(64))))->read($this->source($file));
                self::fail('The configured CMS byte limit must be enforced.');
            } catch (ParseException $error) {
                self::assertStringNotContainsString('user', $error->getMessage());
            }
        }
    }

    public function testRequiredIntegrityAlsoRejectsACompletelyUnprotectedSource(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf/classic.pdf');
        self::assertNotFalse($bytes);
        $source = new FileBytes($bytes);
        self::assertNull((new DocumentReader())->read($source)->mac());
        $this->expectException(ParseException::class);
        (new DocumentReader(sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->read($source);
    }

    public function testImmutableCompositionPreservesCredentialsAndCannotLowerRequiredIntegrity(): void
    {
        $permissive = new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user')));
        self::assertSame(SourceIntegrityPolicy::AcceptUnprotected, $permissive->sourceIntegrityPolicy());
        $required = $permissive->requiringSourceIntegrity(SourceIntegrityPolicy::RequirePdfMac);
        self::assertNotSame($permissive, $required);
        self::assertSame(SourceIntegrityPolicy::RequirePdfMac, $required->sourceIntegrityPolicy());
        self::assertSame($required, $required->requiringSourceIntegrity(SourceIntegrityPolicy::AcceptUnprotected));
        self::assertSame('Standalone', $required->read($this->source('pyhanko-standalone-sha256.pdf'))->mac()?->value);
    }

    public function testImmutableCompositionPreservesTheOriginalSourceLimits(): void
    {
        $reader = (new DocumentReader(limits: new \PDF\Reading\ReaderLimits(maxFileBytes: 1)))->requiringSourceIntegrity(SourceIntegrityPolicy::RequirePdfMac);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('file-byte limit');
        $reader->read($this->source('pyhanko-standalone-sha256.pdf'));
    }

    private function read(FileBytes $source, string $password = 'user'): ReadDocument
    {
        return (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword($password)), sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->read($source);
    }

    private function source(string $filename): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf-mac/' . $filename);
        self::assertNotFalse($bytes);
        return new FileBytes($bytes);
    }
}
