# Component reference

English | [Français](fr/components.md)

The root namespace is `PDF\Reading`. Primitives such as `DictionaryType`,
`ArrayType`, `NameType`, `IntegerType`, `StringType` and `ObjectIdentifier` come
from the [shared PDF model](https://github.com/PDFToolkit/pdf/tree/master/src/Syntax).

## Document and source objects

| Component | Construction and role |
| --- | --- |
| `FileBytes` | `new FileBytes($bytes)` retains explicitly supplied bytes. No file or network access. |
| `DocumentReader` | `new DocumentReader($limits, $authentication)`; `read(FileBytes): ReadDocument` opens the structural document. Limits have explicit defaults. |
| `ReadDocument` | Carries the source, header, effective trailer, index, budgets and caches. Normally constructed by `DocumentReader`. |
| `ObjectResolver` | `resolve(BasicType): BasicType` interface. Resolves a reference in a document without traversing the entire graph. |
| `ObjectReference` | `new ObjectReference(ObjectIdentifier)`; `identifier` property. The reference carries an identifier, without a target or resolver. Serialisation is rejected. |
| `EncodedStream` | `dictionary()` exposes structure; `encodedBytes()` is ready for codecs and `originalEncodedBytes()` preserves source ciphertext. Serialisation is rejected. |

### `ReadDocument` methods

| Method | Result and behaviour |
| --- | --- |
| `header(): FileHeader` | Version declared in the physical header. An optional catalog `/Version` remains accessible in its dictionary. |
| `trailer(): DictionaryType` | Effective trailer, assembled with priority given to recent revisions. In an xref-stream document it contains that stream dictionary's entries. |
| `catalog(): DictionaryType` | Resolves `/Root`; checks `/Type /Catalog`, the `/Pages` reference and declared version. |
| `object(ObjectIdentifier): BasicType` | Loads the identified object; returns null for a missing, free, unavailable or different-generation object. |
| `resolve(BasicType): BasicType` | Calls `object()` for a reference; preserves a direct value unchanged. |
| `streamData(EncodedStream): string` | Decodes the stream under document budgets. Subsequent accesses reuse the result. |

These operations load the base graph. The page services below add explicitly
requested traversal; loading the catalog alone does not visit that tree.

## Physical views

`DocumentReader::readRevisionHistory(FileBytes)` returns a countable, iterable
`ReadRevisionHistory` of oldest-to-newest `ReadRevision` views. Each keeps
`crossReference`, `boundary`, `rawDocument()` and `source(?int $byteLength)`.
`acceptsByteLength()` checks the EOF interval without choosing signed bytes.
`ReadDocument::crossReferences()` exposes the effective index, whose `entries()`
includes free and unavailable records; `objectSpan(ObjectIdentifier)` returns
the ordinary extent or null. See the [physical contract](revision-history.md) for
bounds, hybrid supplements, grouped linearized sections, Contents, budgets and authentication boundaries.

## Pages, inheritance and contents

| Component | Construction and role |
| --- | --- |
| `PageTreeReader` | `new PageTreeReader(ReadDocument, PageTreeLimits)`; `read(): ReadPageTree` traverses the complete tree in `/Kids` order. Checks types, counts, parents, cycles, repeated identities and ceilings. |
| `ReadPageTree` | Immutable countable, iterable collection produced after validation. Properties `document`, `root` and `pages`; `page(int $index): ReadPage` is zero-based and rejects an out-of-range index. |
| `ReadPage` | Immutable leaf view: properties `document`, `reference`, `dictionary` and `attributes`. Retains the source dictionary. |
| `PageAttributes` | Effective resources as `DictionaryType`, MediaBox and CropBox as `Rectangle`, rotation as `IntegerType`. `normalizedRotation(): int` derives a 0/90/180/270 orientation without replacing the original integer. |
| `PageInheritance` | An ancestor's partial values, all optional. `effective(): PageAttributes` requires Resources/MediaBox and applies defaults only at the leaf. |
| `PageAttributeReader` | Receives an `ObjectResolver`. `read(DictionaryType, PageInheritance): PageInheritance` resolves the four inheritable entries and their types without visiting resource contents. |
| `PageTreeTraversal` | State for one traversal operation: explicit stack, visited identities, leaf order and subtree counts. |
| `PageTreeFrame` | A branch in progress: reference, inheritance, declared count and remaining children. Validates its count after visiting its descendants. |
| `PageTreeLimits` | Strictly positive ceilings: defaults are `maxDepth` 64, `maxNodes` 100,000 and `maxPages` 100,000. |
| `PageContentsReader` | `new PageContentsReader(ReadDocument, PageContentLimits)`; `read(ReadPage): PageContents` resolves local contents of a page from the same document. |
| `PageContents` | Immutable countable, iterable collection: properties `page` and `streams`, preserved in order. No concatenation or decompression. |
| `PageContentLimits` | Strictly positive `maxStreams` ceiling, default 1,024 per page. |

The four inheritable entries are `Resources`, `MediaBox`, `CropBox` and `Rotate`.
Local Resources replaces the inherited dictionary without merging. Null is
treated as absence. CropBox defaults to MediaBox and Rotate to zero; other
page attributes remain accessible in the source dictionary. Contents is never
inherited. Absence, null and an empty array yield an empty content collection.

`ReadDocument` budgets remain shared during these operations. Tree and content
ceilings belong to each traversal or reading request. See the
[page guide](pages.md) for an explained example.

## Syntax and structures

| Component | Role |
| --- | --- |
| `ByteCursor` | Bounded byte view, constructed with an offset and optional end. `position()`, `remaining()`, `peek()`, `read()`, `seek()`, `eof()` and `skipWhitespaceAndComments()` control movement. |
| `SyntaxParser` | Receives a cursor, limits and optional budget. `read()` reads a PDF value; `unsignedInteger()`, `keyword()` and `atKeyword()` support structural readers. Does not resolve references. |
| `IndirectObjectReader` | Receives source, limits and budget. `read($offset, $expected, $resolver)` validates an object's envelope, identifier and exact stream extent. |
| `CrossReferenceReader` | Receives source, limits, budget and decoder. `read($offset)` produces a `CrossReference\Section` from a table or xref stream. |
| `ObjectStreamReader` | Receives limits and budget. `read($stream, $decodedBytes, $resolver)` parses ObjStm members in their respective slices. |
| `DictionaryAccess` | Typed structural access to a dictionary, with an optional `ObjectResolver`: `value()`, `integer()` and `hasName()`. |

### `PDF\Reading\CrossReference` namespace

| Component | Role |
| --- | --- |
| `EntryKind` | Enum distinguishing `Free`, `Uncompressed`, `Compressed` and `Unavailable` entries. |
| `Entry` | Value carrying a number, type and two type-specific fields. `generation()` gives the effective generation; compressed members have generation zero. |
| `Section` | Revision value: offset, trailer, optional `/Prev` offset and entry list. |
| `Index` | Effective sparse index, constructed from newest to oldest sections. `entry($number)` retrieves the prevailing entry. |

The index retains recent free entries to prevent a deleted old object from
reappearing. Unknown xref types remain unavailable and resolve to null.

## Filters and predictors

`StreamDecoder::decode(EncodedStream, ReadBudget, ?ObjectResolver): string`
interprets the `/Filter` chain and its `/DecodeParms`. Full filter names are
used. An unknown filter is rejected at decoding time without preventing its
encoded bytes from being retained.

External-file streams require an explicit `ExternalStreamSource`; see the [external stream guide](external-streams.md) for FFilter, acquisition and cumulative limits.

### `PDF\Reading\Filters` namespace

| Component | Role |
| --- | --- |
| `Decoder` | Transformations' `decode(string, ReadBudget): string` interface. |
| `FlateDecoder` | Incremental zlib decompression in small slices, with checks for invalid or truncated data and trailing bytes. |
| `AsciiHexDecoder` | ASCIIHex decoding: PDF whitespace, hexadecimal digits and an optional final nibble. |
| `Ascii85Decoder` | ASCII85 decoding, complete or partial blocks and the `z` shorthand. |
| `RunLengthDecoder` | Decodes RunLength repetitions and literal segments. |
| `PredictorParameters` | `Predictor`, `Colors`, `BitsPerComponent`, `Columns` value. Predictors 1, 2 or 10–15; 1-, 2-, 4-, 8- and 16-bit components. |
| `PredictorDecoder` | Reconstructs TIFF or PNG rows after Flate or LZW. PNG row tags identify the applied algorithm. |
| `DecodedBytes` | Accumulator checking the output limit and cumulative budget before retaining a slice. |

DCTDecode, JPXDecode, CCITTFaxDecode and JBIG2Decode are not implemented.
Crypt is handled by the selected authenticated document context, before codecs. Structural reading of an image stream may therefore succeed
while a decoding request is rejected.

## Limits and diagnostics

| Component | Role |
| --- | --- |
| `ReaderLimits` | Immutable ceiling values, configurable through named arguments. All must be strictly positive. |
| `ReadBudget` | Cumulative counters for parsed values, xref entries, retained bytes, decoded bytes and external acquisition attempts, scoped to one read. |
| `ParseException` | Structure or budget error; optional `offset` property for a position in bytes. |
| `UnsupportedPdfFeature` | `ParseException` subtype distinguishing a deliberately unsupported mechanism. |

Configuration example:

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\ReaderLimits;

$reader = new DocumentReader(new ReaderLimits(
    maxFileBytes: 8 * 1024 * 1024,
    maxObjects: 10_000,
    maxCrossReferenceEntries: 20_000,
    maxDecodedStreamBytes: 16 * 1024 * 1024,
    maxTotalDecodedBytes: 32 * 1024 * 1024,
    maxRetainedBytes: 16 * 1024 * 1024,
));
```

Low-level calls can receive an explicitly supplied budget. To retain the same
ceilings throughout a read, use the same `ReaderLimits` and `ReadBudget`
within the composed components.

[Architecture](architecture.md) · [Security and defaults](security.md)

## Authentication and retained bytes

`Security\StandardPasswordAuthentication` implements `DocumentAuthentication`.
Its constructor receives `PdfDocEncodedPassword` for R2–R4 or
`PreparedPassword` for R5–R7. `ReadDocument::permissions()` exposes the common
`DocumentPermissions::effectiveMask()` contract. Standard returns immutable
`ReadPermissions`: `mask`, `access`, `revision`, `effectiveMask()`.
`PublicKeyAuthentication` returns `PublicKeyReadPermissions` with per-filter
recipient facts, explicit `subFilter` and no password identity or revision.
See [certificate authentication](public-key-authentication.md).
`EncodedStream::originalEncodedBytes()` preserves source bytes;
`encodedBytes()` exposes decrypted bytes ready for decompression.
`Filters\LzwDecoder` receives the core `EarlyChange` value;
`LzwCodeReader` bounds the MSB-first 9–12-bit code input.
See [construction and limits](authentication.md).

`Security\SourcePdfMacVerification` receives an explicit CMS byte limit and
verifies the source before decryption. `SourceIntegrityPolicy` selects whether
an unprotected source is acceptable; `ReadDocument::mac()` reports the verified
`PdfMacLocation` without asserting signature trust. `Security\PdfMacCoverage`
checks the actual source token's identity and extent. `SourceRevision` retains
the latest physical trailer and original bytes; `SourceStringSpans` tracks the
parser-created strings by object identity under the document budget. Decoded
object-stream strings cannot masquerade as source-file spans.
See [source PDF MAC](source-mac.md).

`StructuralPolicy::Pdf20` enables the [targeted EC3 checks](structural-policy.md). `Compatible` remains the default; the policy is preserved by authentication, physical views and integrity-policy composition.
