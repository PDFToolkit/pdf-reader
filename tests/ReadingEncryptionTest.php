<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncryptedPdfException;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independently written tables and streams; no PDFToolkit writer or decryption is used. */
final class ReadingEncryptionTest extends TestCase
{
    #[DataProvider('nullEncryptionEntries')]
    public function testNullEncryptionIsEquivalentToAnAbsentEntry(string $entry, bool $modern, string $version): void
    {
        $document = (new DocumentReader())->read($this->file($entry, $modern, $version));
        self::assertInstanceOf(DictionaryType::class, $document->catalog());
        $value = $document->trailer()->get('Encrypt');
        self::assertNotNull($value);
        self::assertInstanceOf(NullType::class, $document->resolve($value));
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function nullEncryptionEntries(): iterable
    {
        foreach (['1.7', '2.0'] as $version) {
            foreach ([false, true] as $modern) {
                foreach (['direct null' => 'null', 'null object' => '3 0 R', 'missing object' => '99 0 R', 'old generation' => '3 1 R'] as $name => $entry) {
                    yield $version . '/' . ($modern ? 'stream' : 'table') . '/' . $name => [$entry, $modern, $version];
                }
            }
        }
    }

    #[DataProvider('encryptedDeclarations')]
    public function testANonNullEncryptionDictionaryRetainsTheTypedException(string $entry, bool $modern): void
    {
        $this->expectException(EncryptedPdfException::class);
        (new DocumentReader())->read($this->file($entry, $modern, '1.7', [3 => '<< /Filter /Standard >>']));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function encryptedDeclarations(): iterable
    {
        foreach ([false, true] as $modern) {
            yield ($modern ? 'stream' : 'table') . '/indirect' => ['3 0 R', $modern];
            yield ($modern ? 'stream' : 'table') . '/direct' => ['<< /Filter /Standard >>', $modern];
        }
    }

    #[DataProvider('malformedDeclarations')]
    public function testMalformedEncryptionValuesAreParseErrorsRatherThanAuthenticationSignals(string $entry, bool $modern): void
    {
        try {
            (new DocumentReader())->read($this->file($entry, $modern));
            self::fail('A non-dictionary encryption declaration must be rejected.');
        } catch (ParseException $error) {
            self::assertSame(ParseException::class, $error::class);
            self::assertStringContainsString('Encrypt entry must resolve to an encryption dictionary', $error->getMessage());
            self::assertStringNotContainsString('private-source-data', $error->getMessage());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function malformedDeclarations(): iterable
    {
        foreach ([false, true] as $modern) {
            foreach (['boolean true' => 'true', 'boolean false' => 'false', 'number' => '42', 'name' => '/private-source-data', 'string' => '(private-source-data)', 'array' => '[]'] as $name => $entry) {
                yield ($modern ? 'stream' : 'table') . '/' . $name => [$entry, $modern];
            }
        }
    }

    public function testAnIndirectNonDictionaryIsNotAnAuthenticationSignal(): void
    {
        try {
            (new DocumentReader())->read($this->file('3 0 R', false, '1.7', [3 => '(private-source-data)']));
            self::fail('An indirect non-dictionary must be rejected.');
        } catch (ParseException $error) {
            self::assertSame(ParseException::class, $error::class);
            self::assertStringNotContainsString('private-source-data', $error->getMessage());
        }
    }

    public function testAnEncryptionDictionaryIsDetectedBeforeAnEncryptedCatalogIsInterpreted(): void
    {
        $this->expectException(EncryptedPdfException::class);
        (new DocumentReader())->read($this->file('3 0 R', false, '1.7', [1 => 'null', 3 => '<< /Filter /Standard >>']));
    }

    /** @param array<int, string> $overrides */
    private function file(string $encryption, bool $modern = false, string $version = '1.7', array $overrides = []): FileBytes
    {
        $objects = $overrides + [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [] /Count 0 >>',
            3 => 'null',
        ];
        ksort($objects);
        $bytes = '%PDF-' . $version . "\n";
        $offsets = [];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . " 0 obj\n" . $value . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $size = max(array_keys($objects)) + 1;
        if ($modern) {
            $offsets[$size] = $xref;
            $payload = pack('CNn', 0, 0, 65535);
            for ($number = 1; $number <= $size; ++$number) {
                $payload .= isset($offsets[$number]) ? pack('CNn', 1, $offsets[$number], 0) : pack('CNn', 0, 0, 0);
            }
            $bytes .= $size . ' 0 obj << /Type /XRef /Size ' . ($size + 1) . ' /Root 1 0 R /W [1 4 2] /Encrypt ' . $encryption . ' /Length ' . strlen($payload) . ">>\nstream\n" . $payload . "\nendstream\nendobj\n";
        } else {
            $bytes .= 'xref' . "\n0 " . $size . "\n0000000000 65535 f \n";
            for ($number = 1; $number < $size; ++$number) {
                $bytes .= isset($offsets[$number]) ? sprintf("%010d 00000 n \n", $offsets[$number]) : "0000000000 00000 f \n";
            }
            $bytes .= 'trailer << /Size ' . $size . ' /Root 1 0 R /Encrypt ' . $encryption . ">>\n";
        }
        return new FileBytes($bytes . "startxref\n" . $xref . "\n%%EOF\n");
    }
}
