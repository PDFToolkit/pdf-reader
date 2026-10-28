# External stream data

English | [Français](fr/external-streams.md)

An external stream uses `F` to select a file specification. Its local stream bytes
are ignored for decoding; `FFilter` and `FDecodeParms` describe the supplied file
bytes. `EncodedStream` still preserves the local bytes for inspection. These rules
come from ISO 32000-2:2020, Table 5 (physical pages 47–48 in the retained EC3 PDF).

Supply an `ExternalStreamSource` explicitly to `DocumentReader` or `StreamDecoder`.
Without it, external decoding raises `UnsupportedPdfFeature`. Parsing a catalog or
inspecting physical revisions does not acquire ordinary external data. Xref parsing
never receives this capability. An external file is not fetched automatically from
a filesystem, URL, attachment, or platform-dependent name.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\ExternalStreamRequest;
use PDF\Reading\ExternalStreamSource;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\StringType;

final readonly class PublishedAssets implements ExternalStreamSource
{
    /** @param array<string, FileBytes> $assets Already acquired by the application. */
    public function __construct(private array $assets) {}

    public function read(ExternalStreamRequest $request): FileBytes
    {
        // This application's policy uses exact F bytes for its known assets.
        $name = $request->specification instanceof StringType
            ? $request->specification : $request->field('F');
        $bytes = $name instanceof StringType ? ($this->assets[$name->value] ?? null) : null;
        if ($bytes === null || strlen($bytes->bytes) > $request->maximumBytes) {
            throw new RuntimeException('No bounded asset is supplied for this file specification.');
        }
        return $bytes;
    }
}

$reader = new DocumentReader(externalStreams: new PublishedAssets([
    'content.bin' => new FileBytes($alreadyAcquiredEncodedBytes),
]));
$document = $reader->read(new FileBytes($pdfBytes));
// $document->streamData($stream) acquires this stream only on the first decoded access.
```

`ExternalStreamRequest::specification` contains the resolved string or dictionary;
`field($name)` resolves a dictionary field with the owning document's resolver and
bounds. The application chooses how to interpret `F`, `UF`, `FS`, platform names,
embedded-file entries and missing assets. Their presence is not acquisition authority.
The example deliberately implements a small in-memory asset policy.

`ReaderLimits::maxExternalStreamBytes` bounds each acquisition (64 MiB by default).
The source must enforce `maximumBytes` during acquisition; the library checks the
returned size again. `maxExternalStreamRequests` bounds attempts per document
(default 64), including failed attempts. Returned bytes count against retained-byte
limits; every decoding stage uses the existing per-stream and cumulative decoded
limits. Repeated `ReadDocument::streamData()` access uses its document-local cache.
Standalone `StreamDecoder` calls each request data and share the supplied budget.

PDF authentication authenticates the PDF source and its permissions. A source PDF
MAC does not authenticate externally supplied file bytes. `requiringSourceIntegrity()`
retains the explicitly supplied capability and never changes this boundary.

## Independent validation

`tools/validate_external_streams.py` creates eight PDFs with unmodified pyHanko:
string/dictionary/indirect specifications and cascaded filters, each clear or AES-256
encrypted. It applies pypdf's independent codecs to the actual external filter
entries and compares all decoded bytes with Reader. qpdf checks every PDF without
recovery. The oracle checks preserved local bytes, lazy acquisition, caching and
separate document authentication. Native tests also cover invalid shapes, absent
capabilities, null references, parameter resolution and shared resource ceilings.

[Components](components.md) · [Security](security.md) · [Source MAC](source-mac.md)
