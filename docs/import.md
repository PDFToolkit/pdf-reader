# Explicit import into the writer

English | [Français](fr/import.md)

Reader 0.7 provides `PDF\Reading\Import\ImportSession` for copying a selected source graph into native writer objects. It requires core 0.11 or newer. This is an in-memory graph operation: source loading, permission decisions, output paths and the destination PDF version belong to the application.

```php
use PDF\FileStructure;
use PDF\Reading\Import\ImportLimits;
use PDF\Reading\Import\ImportSession;
use PDF\Reading\Import\StreamImportMode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\IndirectReference;

// $document is an explicitly read/authenticated ReadDocument.
$sourceRoot = $document->trailer()->get('Root');
if ($sourceRoot === null) {
    throw new LogicException('Missing source catalog.');
}
$graph = (new ImportSession(
    $document,
    StreamImportMode::PreserveEncoded,
    new ImportLimits(maxObjects: 10000, maxBytes: 33554432),
))->import($sourceRoot);
$root = $graph->root();
if (!$root instanceof IndirectReference) {
    throw new LogicException('Expected a native catalog reference.');
}
// Choose the destination version explicitly; writer validation still applies.
$bytes = (new FileStructure(new FileHeader('2.0'), new FileBody(), $root))->toPdf();
```

The executable [CLI example](../examples/import.php) takes an input path, a distinct new output path and an explicit `1.7` or `2.0` version. It never overwrites its source or an existing output.

## Identity and atomicity

A session belongs to one `ReadDocument`. Pass every root that needs shared identity in the same `import($first, $second, ...)` call; use `ImportedGraph::$roots` for multiple results. `root()` requires one result. `reference($sourceIdentifier)` looks up the corresponding native reference within this graph's source document only.

The importer resolves aliases, distinguishes object number and generation, and assigns new automatic native references. Two documents with the same source numbers remain separate through separate sessions. Missing, free and unavailable generations retain the reader's PDF-null behavior. Dictionaries, arrays and streams are allocated before traversal and initialized exactly once; ordinary page-tree `/Parent` cycles, array cycles and stream dictionary cycles therefore retain identity. Indirect chains with no terminal object are rejected.

A session is usable once, including after failure. No incomplete result is returned or retained for resumption. `ObjectReference` and `EncodedStream` remain immutable read views whose serialization throws. The importer creates native values without calling their serialization methods.

Every reachable object is copied. This does not prune a page's `/Parent` or annotation back-links, merge document-level name trees, update references embedded inside content bytes, or repair invalid source semantics. Choosing a single page can therefore reach its whole page tree. Select an appropriate subgraph explicitly; the destination writer validates the graph it actually emits.

## Stream policy

`StreamImportMode` is a required argument:

| Mode | Result |
| --- | --- |
| `PreserveEncoded` | Keep `encodedBytes()` and the ordered filter/parameter graph. No codec executes for an ordinary local stream; opaque image codecs can remain encoded. |
| `Decode` | Decode through the reader's bounded codecs and emit an unfiltered native stream. An unavailable codec fails explicitly. |

XML metadata (`/Type /Metadata` or `/Subtype /XML`) always uses decoded source bytes, even in preserve mode. Effective PDF 2.0 sources, including a catalog version override, are checked with `XmpUtf8Encoding`. PDF 1.7 metadata is not silently transcoded: the destination writer checks its encoding if the application upgrades to PDF 2.0. An encoded stream cannot masquerade as decoded UTF-8 emission evidence.

Authenticated source streams contribute their encoded **plaintext**, never `originalEncodedBytes()` ciphertext. Original bytes remain unchanged. Authentication removes source encryption; an initial remaining `/Crypt /Identity` is validated and dropped. Other remaining Crypt filters are rejected. The new writer's encryption policy is a separate explicit operation.

`/Length`, `/Filter` and `/DecodeParms` are rebuilt by the native stream. `/DL` remains a source hint. Filter parameters such as `JBIG2Globals` have their references remapped. `/ObjStm` and `/XRef` transport streams are rejected because their embedded offsets and object numbers cannot be copied unchanged; their resolved logical objects can be imported.

External `/F` streams are rejected unless an `ExternalStreamSource` is supplied explicitly to the import session, independently of any source-reader capability. The bridge acquires it once, applies `FFilter`/`FDecodeParms` and embeds the result under the selected stream mode. No path or URL is opened by the library, and external location entries are not retained on the new stream.

## Bounds and security authority

`ImportLimits` bounds source objects, visited values, traversal depth, retained graph bytes, individual stream bytes, filters, cycle edges and external requests. Set `maxCycles: 0` to reject cycles. Limits are cumulative across all roots, including aliases and repeated references. The byte limit also bounds cumulative decoded work through a separate stream-decoding counter; intermediate decoder allocations retain the reader's existing bounded contracts. The source document's own read limits continue to apply.

The bridge rejects source encryption dictionaries, non-null `Encrypt`/`AuthCode` entries, signature/timestamp dictionaries, signed signature fields and signature dictionaries identified by Contents plus ByteRange or SubFilter. It does not silently remove a signature while claiming that the document remains signed. An unsigned content subgraph may be selected from a signed document, but the application must decide whether reuse is permitted.

No `SourceRevision` is converted into a native `FileRevision`; no signature-edit authorization, source authentication result or PKI trust is transferred. Copying is not an incremental edit of the source. Reader permissions are available for the application's policy and are not automatically treated as authorization to write or distribute a new PDF.

## Evidence and source scope

The contract follows ISO 32000-2:2020 clauses 7.3.8–7.3.10 (physical pages 46–49), 7.4.1 (49–50), 7.6 (source encryption), and EC3 #296 (730, metadata UTF-8). [The ledger](../resources/import.json) connects this scope to the implementation and validations; it is not a claim of complete PDF semantic conformance.

`ReadingImportTest` and `ReadingImportStreamsTest` cover graph identity, cycles, generations, multiple sources, atomic failure, cumulative bounds, authenticated ciphertext preservation, external capability boundaries and metadata upgrade behavior. `tools/validate_import.py` independently checks both modes on classic and compressed-object PDFs, qpdf RC4/AES password sources and a pyHanko certificate-encrypted source. pypdf checks content, encoded bytes and graph links; qpdf checks every resulting PDF.

[Architecture](architecture.md) · [Authentication](authentication.md) · [Security](security.md)
