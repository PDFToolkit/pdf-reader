# PDF Reader

English | [Français](README.fr.md)

A PHP reader for external PDFs, built around the objects of **ISO 32000-1:2008**
and **ISO 32000-2:2020**.
It exposes document dictionaries, arrays, references and streams with explicit
resolution. Requires **PHP 8.3** or newer; CI runs checks on **PHP 8.3, 8.4 and 8.5**.

This repository complements [PDFToolkit/pdf](https://github.com/PDFToolkit/pdf),
which provides the primitives and writer. `pdftoolkit/pdf-reader` depends on
that model; the model does not depend on the reader. Both packages use the
same objects for names, strings, numbers, arrays, dictionaries and identifiers.

## Installation

The package is available on
[Packagist](https://packagist.org/packages/pdftoolkit/pdf-reader). In your
application:

```console
composer require "pdftoolkit/pdf-reader:^0.6"
```

The reader uses `roukmoute/pdf:^0.9 || ^0.10 || ^0.11`, installed
automatically by Composer. PHP 8.3 or newer on a 64-bit platform and the `ctype`,
`iconv` and `zlib` extensions are required for this combination. The application's
`composer.lock` records the selected versions and revisions.

Version 0.5 accepts PDF 1.0–1.7 and PDF 2.0 syntax, including the catalog
`/Version /2.0` override, using the same bounded object and stream readers.
This makes PDF 2.0 dictionaries available to optional interpreters such as
`pdftoolkit/pdf-to-html`; it does not interpret every PDF 2.0 feature.
Standard password and Adobe.PubSec certificate authentication are explicit; unsupported security handlers,
codecs, rendering and importing remain explicit limits. See the
[authentication guide](docs/authentication.md) for construction and boundaries.

Reader 0.5 authenticates source PDF MACs with explicit credentials before content
interpretation. Both standalone and signature-attached locations are supported;
applications can require integrity with `SourceIntegrityPolicy::RequirePdfMac`.
See the [source MAC guide](docs/source-mac.md) for exact coverage and limits.

Reader 0.6 exposes [physical revision histories](docs/revision-history.md): exact
prefixes, xref sections, effective objects and Contents provenance under shared
budgets. Raw inspection precedes separate authentication; it grants no signature
or modification authority.

External-file data can be supplied through an explicit application object. See the [external stream guide](docs/external-streams.md) for filter selection and bounds.

## Read pages

Run this example from a reader checkout after `composer install`.
The application loads the file; the reader receives only its bytes.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$bytes = file_get_contents(__DIR__ . '/tests/Fixtures/page-tree/classic.pdf');
if ($bytes === false) {
    throw new RuntimeException('Could not read the fixture.');
}

$document = (new DocumentReader())->read(new FileBytes($bytes));
$tree = (new PageTreeReader($document, new PageTreeLimits(
    maxDepth: 64,
    maxNodes: 10_000,
    maxPages: 5_000,
)))->read();
printf("Validated page count: %d\n", count($tree));

foreach ($tree as $index => $page) {
    // These are the effective PDF values, after page-tree inheritance.
    $attributes = $page->attributes;
    printf(
        "Page %d; rotation: %d; normalized rotation: %d\n",
        $index + 1,
        $attributes->rotate->integer(),
        $attributes->normalizedRotation(),
    );
    foreach ($attributes->resources->entries() as $entry) {
        printf("Resource category: %s\n", $entry->key->value);
    }
}
```

`PageTreeReader::read()` traverses `/Pages` branches and `/Page` leaves in
`/Kids` order. It checks every `/Count`, `/Parent` relationship, descendant
identity and traversal limit. The resulting `ReadPageTree` is countable and
iterable; `page(0)` identifies its first page. Each `ReadPage` retains its
document, reference and source dictionary, then exposes its effective
`PageAttributes`: resources, MediaBox, CropBox and rotation.

`DictionaryType::get()` always returns the stored value: a reference remains
an `ObjectReference`. `ReadDocument::resolve()` loads that reference in the
relevant document and leaves a direct value unchanged. Previously read objects
are kept in a document-scoped cache; there is no global registry, singleton
or resolution hidden inside values.

Inherited attributes use the closest ancestor that defines them. A local
resource dictionary replaces inherited resources without merging them.
The [page guide](docs/pages.md) explains inheritance and distinguishes written
values from effective values. Traversal does not load contents or every resource
of each page.

## Read stream bytes

`PageContentsReader` resolves `/Contents` into an ordered collection of encoded
streams. It receives the document and its own per-page stream limit.
Continuing the preceding example with the first page of this fixture:

```php
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;

$page = $tree->page(0);
$contents = (new PageContentsReader($document, new PageContentLimits(
    maxStreams: 128,
)))->read($page);

foreach ($contents as $stream) {
    $encodedBytes = $stream->encodedBytes();
    // Decoding is requested explicitly, under the document's shared budgets.
    $decodedBytes = $document->streamData($stream);
}
```

A page with no `/Contents`, null or an empty array yields an empty collection.
Array streams keep their order, without automatic concatenation or decoding.
An `EncodedStream` retains its dictionary and bytes ready for decompression;
`originalEncodedBytes()` retains the original source ciphertext when authenticated; `streamData()`
requests decoding. The result remains bytes: drawing operators, fonts and
encodings are not interpreted to extract text or render a page.

## Current coverage

| Mechanism | Available reading |
| --- | --- |
| Syntax | Escaped names and strings, integers and reals, booleans, null, arrays, dictionaries, indirect references. |
| Structure | PDF 1.0–1.7 and 2.0 headers and catalog versions; indirect objects and streams delimited by `/Length`, including indirect lengths. |
| Traditional xref | Subsections, offsets, generations, free objects and revisions linked by `/Prev`. |
| Xref streams | `/W` fields, `/Index` ranges, free, direct or compressed types; unknown types yield an unavailable object. |
| Hybrid xref | `/XRefStm` entries override the companion table; the table trailer owns `/Prev`; cumulative budgets cover both. |
| Object streams | `/ObjStm` members, with header, offset and expected object number validation. |
| Filters | FlateDecode, LZWDecode (EarlyChange 0/1), ASCIIHexDecode, ASCII85Decode and RunLengthDecode; explicit filter chains and parameters. |
| Predictors | TIFF 2 and PNG 10 to 15, with 1-, 2-, 4-, 8- and 16-bit components. |
| Resolution | A missing, free or different-generation object yields `NullType`. |
| Page tree | Complete ordered traversal, checks for every `/Count` and `/Parent`, rejection of cycles or repeated descendants, dedicated traversal limits. |
| Inheritance | Effective resources, MediaBox, CropBox and rotation; CropBox defaults to MediaBox and absent rotation defaults to zero. |
| Page contents | A stream or an array of streams, kept in order; separate reading and decoding. |

Without explicit credentials, encrypted files raise `EncryptedPdfException`.
Native Standard-handler authentication supports R2–R7 and named crypt filters;
source PDF MACs are verified before protected content is interpreted. Both
standalone and signature-attached MACs are supported; applications can require
one with `SourceIntegrityPolicy::RequirePdfMac`. Adobe.PubSec authentication supports
explicit recipient capabilities, independent filter keys and deferred attachment access.
See the [public-key guide](docs/public-key-authentication.md) and [PDF MAC guide](docs/source-mac.md).
Unimplemented codecs are explicitly rejected. A stream using an unknown codec can be preserved in
encoded form; requesting decoding raises `UnsupportedPdfFeature`.

Import into the writer, rewriting, merging, text extraction, rendering, forms,
signature validation and PDF repair remain to be implemented. External references and
streams reject serialisation: reusing their numbers in a new document would
be incorrect without explicit import and remapping.

## Development

```console
composer install
composer check
composer audit --locked
```

Tests cover syntax, filters, structures, pages and budgets. The
[five structure fixtures](tests/Fixtures/pdf/README.md) and
[two page fixtures](tests/Fixtures/page-tree/README.md) are generated with pypdf
and qpdf, independently of the PHP writer; their bytes and results are checked
when reproduced.

The reader validates a structure when it is loaded. Opening the catalog does
not perform an exhaustive audit of every object in the file. Consult the
[security controls and limits](docs/security.md) before choosing budgets for
an upload service.

## Documentation

Patch 0.3.1 corrects catalog-only metadata exemption and resolves indirect stream
types before selecting embedded-file encryption. Page and object metadata stay
protected by their chosen filter; explicit Identity and codec bytes are preserved.

- [Explicit EC3 structural policy](docs/structural-policy.md)
- [CCITT fax decoding](docs/fax.md)
- [External stream data](docs/external-streams.md)
- [Physical revision history](docs/revision-history.md)
- [Architecture and object boundaries](docs/architecture.md)
- [Pages, inheritance and content streams](docs/pages.md)
- [Component reference](docs/components.md)
- [Password authentication](docs/authentication.md)
- [Security controls](docs/security.md)
- [Reporting policy](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Reader module documentation](https://pdftoolkit.github.io/modules/reader/)
- [Shared documentation for objects, the writer and modules](https://pdftoolkit.github.io/)
- [PDF 1.7 standard included in the model repository](https://pdftoolkit.github.io/downloads/iso-32000-1.pdf)

[MIT](LICENSE) licence.
