<?php

declare(strict_types=1);

namespace PDF\Reading\Tests;

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\ParseException;
use PDF\Syntax\Object\NullType;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringType;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadingEncryptedIdentityChainTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function aliases(): iterable
    {
        yield 'direct' => [false, false, false];
        yield 'encryption chain' => [true, false, false];
        yield 'root chain' => [false, true, false];
        yield 'metadata chain' => [false, false, true];
        yield 'combined chains' => [true, true, true];
    }

    #[DataProvider('aliases')]
    public function testTerminalEncryptionAndCatalogMetadataIdentitiesSurviveCombinedAliases(bool $encrypt, bool $root, bool $metadataAlias): void
    {
        [$source, $owner] = $this->source($encrypt, $root, $metadataAlias);
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read($source);
        self::assertSame(-3392, $document->permissions()?->mask);
        $metadata = $document->resolve($document->catalog()->get('Metadata'));
        self::assertInstanceOf(EncodedStream::class, $metadata);
        self::assertSame('<metadata>R5 visible metadata</metadata>', $document->streamData($metadata));
        $dictionary = $document->resolve($document->trailer()->get('Encrypt'));
        self::assertInstanceOf(DictionaryType::class, $dictionary);
        self::assertInstanceOf(StringType::class, $dictionary->get('O'));
        self::assertSame($owner, bin2hex($dictionary->get('O')->value));
        $pageMetadata = $document->resolve($document->catalog()->get('FixturePageMetadata'));
        self::assertInstanceOf(EncodedStream::class, $pageMetadata);
        self::assertNotSame($pageMetadata->originalEncodedBytes(), $pageMetadata->encodedBytes());
        self::assertStringContainsString('R5', $document->streamData($pageMetadata));
    }

    public function testAMetadataAliasWithAnUnavailableGenerationIsNullRatherThanAnExemption(): void
    {
        [$source] = $this->source(true, true, true, [15 => '3 1 R']);
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read($source);
        self::assertSame(-3392, $document->permissions()?->mask);
        self::assertInstanceOf(NullType::class, $document->resolve($document->catalog()->get('Metadata')));
        $private = $document->resolve($document->catalog()->get('FixturePageMetadata'));
        self::assertInstanceOf(EncodedStream::class, $private);
        self::assertNotSame($private->originalEncodedBytes(), $private->encodedBytes());
    }

    public function testACatalogMetadataAliasCannotGrantAnExemptionToAnotherStreamType(): void
    {
        $bytes = '<metadata>R5 visible metadata</metadata>';
        [$source] = $this->source(true, true, true, [3 => '<< /Type /Other /Length ' . strlen($bytes) . ">>\nstream\n" . $bytes . "\nendstream"]);
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read($source);
        $this->expectException(ParseException::class);
        $document->resolve($document->catalog()->get('Metadata'));
    }

    #[DataProvider('cycles')]
    public function testEncryptionRootAndMetadataAliasCyclesFailWithinTheirDocumentLimits(array $override, bool $readMetadata): void
    {
        [$source] = $this->source(true, true, true, $override);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('indirect-reference chain');
        $document = (new DocumentReader(authentication: new StandardPasswordAuthentication(new PreparedPassword('user'))))->read($source);
        if ($readMetadata) {
            $document->resolve($document->catalog()->get('Metadata'));
        }
    }

    /** @return iterable<string, array{array<int, string>, bool}> */
    public static function cycles(): iterable
    {
        yield 'encryption' => [[14 => '11 0 R'], false];
        yield 'root' => [[16 => '13 0 R'], false];
        yield 'metadata' => [[15 => '12 0 R'], true];
    }

    /** @param array<int, string> $override @return array{FileBytes, string} */
    private function source(bool $encrypt, bool $root, bool $metadataAlias, array $override = []): array
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/encryption/qpdf-r5-metadata-clear.pdf');
        self::assertNotFalse($bytes);
        self::assertSame(1, preg_match('/1 0 obj\s*(<<.*?>>)\s*endobj/s', $bytes, $catalog));
        self::assertSame(1, preg_match('/10 0 obj\s*(<<.*?>>)\s*endobj/s', $bytes, $encryption));
        self::assertSame(1, preg_match('/\/O <([0-9a-f]+)>/', $encryption[1], $owner));
        self::assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/', $bytes, $previous));
        $catalogValue = $metadataAlias ? str_replace('/Metadata 3 0 R', '/Metadata 12 0 R', $catalog[1]) : $catalog[1];
        $catalogValue = substr($catalogValue, 0, -2) . ' /Version /2.0 /FixturePageMetadata 17 0 R >>';
        $objects = $override + [1 => $catalogValue, 11 => '14 0 R', 12 => '15 0 R', 13 => '16 0 R', 14 => '10 0 R', 15 => '3 0 R', 16 => '1 0 R', 17 => '18 0 R', 18 => '8 0 R'];
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
        $bytes .= 'trailer << /Size 19 /Root ' . ($root ? '13 0 R' : '1 0 R') . ' /Encrypt ' . ($encrypt ? '11 0 R' : '10 0 R') . ' /Prev ' . $previous[1] . " >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return [new FileBytes($bytes), $owner[1]];
    }
}
