# Reader security controls

English | [Français](fr/security.md)

The reader parses external PDF bytes. This documentation describes implemented
protections and scenarios covered by the project's tests. It is not an external
audit or a guarantee of freedom from vulnerabilities. Scope includes syntax,
object resolution, revisions, pages and supported filters.

Without an authentication capability, an effective non-null `/Encrypt`
entry resolving to a dictionary raises `EncryptedPdfException`. Null references
mean absence; other types are parse errors. An injected
`StandardPasswordAuthentication` authenticates before catalog interpretation,
checks encrypted R5–R7 permissions and decrypts objects once under shared budgets.
See the [authentication guide](authentication.md) for R2–R7, explicit password
preparation, [source PDF MAC verification](source-mac.md), and the unsupported
public-key boundary.

## Checked surfaces

| Surface | Controls and tests |
| --- | --- |
| Source | PDF 1.0–1.7 or 2.0 header at the beginning of bytes, bounded final footer, rejection of sources exceeding the limit. No path or URL loading by the reader. |
| Syntax | Token lengths, depth and value counts; name and string escapes; bounded arrays and dictionaries; duplicate keys and out-of-range numbers rejected. |
| Cursors | Each view has bounds; out-of-view reads and movement are rejected. Compressed objects are parsed in separate slices. |
| Xref | Offsets, generations, types and ranges checked; big-endian fields without integer overflow; non-overlapping subsections and `/Index` ranges. |
| Revisions | Bounded `/Prev` chain and cycle detection; newer entries, including free ones, override older entries. An outdated generation resolves to null. |
| Source MAC | Standard R5–R7 key-bound verification before content, actual payload source spans, exact whole-file coverage, strict standalone framing, bounded signature padding, CMS/HMAC and digest checks. Required-integrity policy rejects clear or stripped sources too. |
| Objects | Actual identifier compared with the xref entry; bounded recursive loading and loading-cycle detection; document-scoped caches. |
| Page tree | Explicit traversal stack; dedicated depth, node and leaf limits; rejection of cycles, repeated descendants, inconsistent counts and incorrect Parent links. |
| Inheritance | Resource, rectangle and rotation types checked; only four inheritable entries; local resources replace inherited values without merging; rotation must be a multiple of 90. |
| Page contents | Page tied to its source document; stream count bounded before array resolution; every element must identify a stream; no automatic inheritance, decoding or interpretation. |
| Streams | Extent determined exclusively by `/Length`. End markers inside binary data do not split the stream. Indirect lengths use the bounded resolver. |
| Retained data | Cumulative budget for names, strings and encoded bytes, checked before retention; repeated or overlapping views cannot multiply copies without a ceiling. |
| ObjStm | `/N`, `/First`, numbers and offsets checked; one value per slice; number identified by the xref index validated; generation zero and uncompressed length. |
| Decompression | Limit on each output, cumulative budget across all stages and bounded filter-chain length; incremental Flate decompression; truncated data and invalid predictor rows rejected. |
| External mechanisms | No execution of PDF or JavaScript actions. Decoding external `/F`, `/FFilter` or `/FDecodeParms` streams is rejected. |
| Writing | External references and streams reject serialisation without import and remapping; the reader opens no output file. |

Tests [ReadingSyntaxTest](../tests/ReadingSyntaxTest.php),
[ReadingFiltersTest](../tests/ReadingFiltersTest.php),
[ReadingDocumentTest](../tests/ReadingDocumentTest.php),
[ReadingFileStructureTest](../tests/ReadingFileStructureTest.php),
[ReadingRetentionTest](../tests/ReadingRetentionTest.php) and
[ReadingPagesTest](../tests/ReadingPagesTest.php) exercise these boundaries.
The five [external fixtures](../tests/Fixtures/pdf/README.md) come from a pypdf
generator and qpdf transformation, independently of the PHP writer.
The two [page fixtures](../tests/Fixtures/page-tree/README.md) add a multilevel
tree, inherited or local attributes and direct or indirect content arrays,
including binary data. Reproduction verifies bytes, strict reading and expected
structural results. Successfully reading these fixtures does not demonstrate
conformance for every possible PDF.

## Default limits

| `ReaderLimits` property | Ceiling |
| --- | --- |
| `maxFileBytes` | 64 MiB source. |
| `maxDepth` | 64 levels of syntax or indirect loading. |
| `maxTokenBytes` | 1 MiB per token; also bounds the footer search. |
| `maxContainerEntries` | 100,000 entries in a container. |
| `maxObjects` | 100,000 for the object ceiling and structural object-number bound. |
| `maxCrossReferenceEntries` | 200,000 entries accumulated across revisions. |
| `maxRevisions` | 64 sections in the `/Prev` chain. |
| `maxDecodedStreamBytes` | 64 MiB for one decoding output. |
| `maxTotalDecodedBytes` | 128 MiB accumulated across decoding stages. |
| `maxFilters` | 16 filters in a chain. |
| `maxParsedValues` | 500,000 cumulative syntax values. |
| `maxRetainedBytes` | 128 MiB accumulated for retained names, strings and stream bytes. |

The decoded budget counts intermediate outputs, for example the Flate output
followed by the predictor output. Xref and object streams use the same budget
as other streams. Accessing an already decoded result in the `ReadDocument`
cache does not trigger decoding again.

These ceilings limit work; they are not a promise about maximum PHP memory
consumption. The source, objects, dictionaries and caches consume additional
memory. A native decoder may also produce a temporary slice before checking
it. Defaults must be adjusted to the application's memory budget and quotas.

Page operations add their own ceilings: `PageTreeLimits` defaults to 64 levels,
100,000 nodes and 100,000 pages; `PageContentLimits` allows 1,024 streams per page.
These counters belong to one operation. Loaded objects and decoded bytes
remain subject to the same `ReadDocument`'s cumulative budgets. See the
[page guide](pages.md) to configure them explicitly.

## Application responsibilities

The application chooses and authorises the origin of bytes. It must bound
their acquisition before constructing `FileBytes`, notably for an upload or
network response. `maxFileBytes` is checked when bytes have already been supplied
to the reader. It does not control an earlier download or file opening.

The reader does not remove actions, attachments or other potentially active
PDF objects. Their existence is retained as data. Showing or redistributing
the original file remains an application decision; successful reading does
not certify that any software can open the document safely.

Resolution is on demand. Opening checks the catalog and xref structures but
does not visit every object or resource. A defect in an object not loaded yet
may be reported during a later access. The reader does not repair damaged
files and rejects unsupported mechanisms, including unverified PDF MACs,
public-key authentication and image-specific decompression codecs.

Diagnostics and extracted values must not be sent to an HTML page without
escaping or published without access controls. Data in dictionaries, names
and strings comes from the document. The application decides what it can
log or present.

## Checks and dependencies

CI runs tests, static analysis and formatting checks on PHP 8.3, 8.4 and 8.5.
`composer audit --locked` checks available advisories for locked dependencies;
it is not an audit of the reader's code and depends on information known at
the time it runs.

PHP, zlib, the server, validation tools and software opening PDFs have their
own security surfaces, outside this library's test scope.

[Private reporting](../SECURITY.md) · [Architecture](architecture.md)

Independent tests compare 26 LZW/predictor cases with qpdf, six hybrid files
with pypdf/qpdf and 24 R2–R7 PDFs encrypted by qpdf or cryptography.

Patch 0.3.1 additionally checks seven source files for metadata ownership and
indirect embedded-file types. `EncryptMetadata=false` exempts only the catalog's
actual Metadata identity, not arbitrary streams declaring `/Type /Metadata`.
The immutable decryption context receives the document root explicitly and uses
the bounded resolver; explicit Identity and remaining codec bytes are preserved.
The [authentication guide](authentication.md) records source clauses and qpdf's
EFF decryption limitation separately from the independent cryptography fixtures.
Modified AES-GCM tags fail without returning plaintext. Authentication
errors do not disclose credentials or document contents.
