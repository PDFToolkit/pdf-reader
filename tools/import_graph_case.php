<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\FileStructure;
use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Reading\Import\ImportSession;
use PDF\Reading\Import\StreamImportMode;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeReader;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\OpenSslIdentity;
use PDF\Security\CertificateChain;
use PDF\Security\X509Certificate;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Explicit CLI I/O surrounds the in-memory importer; the library never opens paths. */
final readonly class ImportGraphCase
{
    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        if (count($arguments) < 6) {
            throw new InvalidArgumentException('Expected source, output, preserve|decode, root|pages, and none|legacy|modern|certificate.');
        }
        [, $input, $output, $mode, $selection, $security] = $arguments;
        $authentication = match ($security) {
            'none' => null,
            'legacy' => new StandardPasswordAuthentication(new PdfDocEncodedPassword(new HexadecimalString('owner'))),
            'modern' => new StandardPasswordAuthentication(new PreparedPassword('owner')),
            'certificate' => new PublicKeyAuthentication([new OpenSslRecipientDecrypter(new OpenSslIdentity(
                new CertificateChain(new X509Certificate(new HexadecimalString($this->bytes($arguments[6] ?? '')))),
                $this->bytes($arguments[7] ?? '')
            ))], PublicKeyPermissionByteOrder::MostSignificantFirst),
            default => throw new InvalidArgumentException('Unknown test authentication.'),
        };
        $source = $this->bytes($input);
        $document = (new DocumentReader(authentication: $authentication))->read(new FileBytes($source));
        $streams = [];
        foreach ((new PageTreeReader($document))->read() as $page) {
            foreach ((new PageContentsReader($document))->read($page) as $stream) {
                $streams[] = $stream;
            }
        }
        $before = array_map($this->stream(...), $streams);
        $session = new ImportSession($document, match ($mode) {
            'preserve' => StreamImportMode::PreserveEncoded,
            'decode' => StreamImportMode::Decode,
            default => throw new InvalidArgumentException('Unknown import mode.'),
        });
        $selected = match ($selection) {
            'root' => $document->trailer()->get('Root'),
            'pages' => $document->catalog()->get('Pages'),
            default => throw new InvalidArgumentException('Unknown root selection.'),
        };
        if ($selected === null) {
            throw new LogicException('Missing fixture root.');
        }
        $root = $session->import($selected)->root();
        if ($selection === 'pages') {
            $root = new IndirectReference(new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), $root)));
        }
        if (!$root instanceof IndirectReference) {
            throw new LogicException('A native catalog reference was expected.');
        }
        $result = (new FileStructure(new FileHeader('1.7'), new FileBody(), $root))->toPdf();
        if (file_put_contents($output, $result) !== strlen($result)) {
            throw new RuntimeException('The selected output could not be written.');
        }
        $after = array_map($this->stream(...), $streams);
        if ($before !== $after || $source !== $document->sourceRevision()->source->bytes) {
            throw new LogicException('The import changed its source.');
        }
        echo json_encode(['sourceSha256' => hash('sha256', $source), 'outputSha256' => hash('sha256', $result), 'sourceStreams' => $before, 'sourceUnchanged' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
    }

    private function bytes(string $path): string
    {
        $bytes = file_get_contents($path);
        return $bytes === false ? throw new RuntimeException('Cannot read a selected fixture input.') : $bytes;
    }

    /** @return array{encodedSha256: string, originalSha256: string} */
    private function stream(EncodedStream $stream): array
    {
        return ['encodedSha256' => hash('sha256', $stream->encodedBytes()), 'originalSha256' => hash('sha256', $stream->originalEncodedBytes())];
    }
}

(new ImportGraphCase())->run(isset($argv) ? $argv : []);
