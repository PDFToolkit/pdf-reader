<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Reading\ParseException;
use PDF\Reading\Security\AuthenticationFailed;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PasswordAccess;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingPasswordAuthenticationTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function independentFiles(): iterable
    {
        yield 'RC4 40 bit' => ['qpdf-r2-40-metadata-encrypted.pdf', 2];
        yield 'RC4 128 bit' => ['qpdf-r3-128-metadata-encrypted.pdf', 3];
        yield 'R4 metadata clear' => ['qpdf-r4-128-metadata-clear.pdf', 4];
        yield 'R4 metadata encrypted' => ['qpdf-r4-128-metadata-encrypted.pdf', 4];
        yield 'R5 metadata clear' => ['qpdf-r5-metadata-clear.pdf', 5];
        yield 'R5 metadata encrypted' => ['qpdf-r5-metadata-encrypted.pdf', 5];
    }

    #[DataProvider('independentFiles')]
    public function testBothPasswordClassesReadActualIndependentEncryptedFiles(string $filename, int $revision): void
    {
        foreach (['user' => PasswordAccess::User, 'owner' => PasswordAccess::Owner] as $password => $access) {
            $credential = $revision <= 4 ? new PdfDocEncodedPassword(new HexadecimalString($password)) : new PreparedPassword($password);
            $document = (new DocumentReader(authentication: new StandardPasswordAuthentication($credential)))->read($this->source($filename));
            self::assertSame($revision, $document->permissions()?->revision);
            self::assertSame($access, $document->permissions()?->access);
            self::assertInstanceOf(DictionaryType::class, $document->catalog());
            $pages = (new PageTreeReader($document))->read();
            self::assertCount(1, $pages);
            foreach ($pages as $page) {
                foreach ((new PageContentsReader($document))->read($page) as $stream) {
                    self::assertInstanceOf(EncodedStream::class, $stream);
                    self::assertStringContainsString('BT', $document->streamData($stream));
                    self::assertNotSame($stream->originalEncodedBytes(), $stream->encodedBytes());
                }
            }
        }
    }

    #[DataProvider('independentFiles')]
    public function testIncorrectPasswordsHaveTheirOwnFailureWithoutSourceOrCredentialLeak(string $filename, int $revision): void
    {
        $password = 'private-incorrect-test-password';
        $credential = $revision <= 4 ? new PdfDocEncodedPassword(new HexadecimalString($password)) : new PreparedPassword($password);
        try {
            (new DocumentReader(authentication: new StandardPasswordAuthentication($credential)))->read($this->source($filename));
            self::fail('An incorrect password must fail before content interpretation.');
        } catch (AuthenticationFailed $error) {
            self::assertStringNotContainsString($password, $error->getMessage());
        }
    }

    public function testAuthenticatedPermissionsRejectTamperedPerms(): void
    {
        $source = $this->source('qpdf-r5-metadata-encrypted.pdf');
        $changed = preg_replace_callback('/\/Perms\s*<([0-9a-fA-F]+)>/', fn (array $match): string => '/Perms <' . ($match[1][0] === '0' ? '1' : '0') . substr($match[1], 1) . '>', $source->bytes, 1, $count);
        self::assertSame(1, $count);
        self::assertNotNull($changed);
        $this->expectException(ParseException::class);
        (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read(new FileBytes($changed));
    }

    public function testUnencryptedFilesDoNotAcquireAnAuthenticatedPermissionsClaim(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/pdf/classic.pdf');
        self::assertNotFalse($bytes);
        $reader = new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('unused')));
        self::assertNull($reader->read(new FileBytes($bytes))->permissions());
    }

    private function source(string $filename): FileBytes
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/encryption/' . $filename);
        self::assertNotFalse($bytes);
        return new FileBytes($bytes);
    }
}
