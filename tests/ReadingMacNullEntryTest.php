<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingMacNullEntryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nullEntries(): iterable
    {
        foreach (['null', '11 0 R', '12 0 R', '99 0 R', '11 1 R'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('nullEntries')]
    public function testClearOptionalNullAuthCodeIsAbsentButCannotSatisfyRequiredIntegrity(string $value): void
    {
        $bytes = $this->fixture('pdf/classic.pdf');
        $source = $this->append($bytes, $value);
        self::assertNull((new DocumentReader())->read($source)->mac());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('policy requires');
        (new DocumentReader(sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac))->read($source);
    }

    #[DataProvider('nullEntries')]
    public function testActualEncryptedR2AndR5FilesWithNullOptionalEntriesRetainAuthentication(string $value): void
    {
        foreach (['qpdf-r2-40-metadata-encrypted.pdf', 'qpdf-r5-metadata-encrypted.pdf'] as $filename) {
            $bytes = $this->fixture('encryption/' . $filename);
            self::assertSame(1, preg_match('/10 0 obj\s*(<<.*?>>)\s*endobj/s', $bytes, $match));
            $dictionary = substr($match[1], 0, -2) . ' /KDFSalt ' . $value . ' >>';
            $source = $this->append($bytes, $value, [10 => $dictionary]);
            $password = str_contains($filename, '-r2-') ? new PdfDocEncodedPassword(new HexadecimalString('user')) : new PreparedPassword('user');
            $document = (new DocumentReader(authentication: new StandardPasswordAuthentication($password)))->read($source);
            self::assertNull($document->mac());
            self::assertSame(str_contains($filename, '-r2-') ? -64 : -3392, $document->permissions()?->mask);
        }
    }

    #[DataProvider('nullEntries')]
    public function testLatestNullAuthCodeCannotReuseAnEarlierRealMac(string $value): void
    {
        $bytes = $this->fixture('pdf-mac/pyhanko-standalone-sha256.pdf');
        $source = $this->append($bytes, $value);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('latest physical trailer');
        (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read($source);
    }

    private function fixture(string $path): string
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/' . $path);
        self::assertNotFalse($bytes);
        return $bytes;
    }

    /** @param array<int, string> $overrides */
    private function append(string $bytes, string $authCode, array $overrides = []): FileBytes
    {
        self::assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/', $bytes, $previous));
        $objects = $overrides + [11 => 'null', 12 => '11 0 R'];
        ksort($objects);
        $offsets = [];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $value . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= "xref\n";
        foreach ($offsets as $number => $offset) {
            $bytes .= $number . " 1\n" . sprintf("%010d 00000 n \n", $offset);
        }
        return new FileBytes($bytes . 'trailer << /Size 13 /Prev ' . $previous[1] . ' /AuthCode ' . $authCode . " >>\nstartxref\n" . $xref . "\n%%EOF\n");
    }
}
