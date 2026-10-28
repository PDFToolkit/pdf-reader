# Physical revision history

English | [Français](fr/revision-history.md)

Reader exposes the actual source prefixes of a chronological incremental
PDF. These are immutable physical views, with the original bytes, cross-reference
sections and effective object identities. They do not establish signature trust,
DocMDP/FieldMDP authority or permission to modify the document.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

// The application supplies and bounds acquisition of these bytes.
$history = (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
foreach ($history as $revision) {
    $raw = $revision->rawDocument();
    $exactPrefix = $revision->source();
    $xrefOffset = $revision->crossReference->section->offset->value;
    $physicalTrailer = $revision->crossReference->section->trailer;
    $effectiveTrailer = $raw->trailer();
    $effectiveEntries = $raw->crossReferences()->entries();
}
```

`ReadRevisionHistory` is countable and iterable, oldest to newest, with zero-based
ordinals and `latest()`. Its `source` preserves every supplied byte. Each raw
`ReadDocument` resolves objects using only that prefix's effective index: later
replacements cannot leak into earlier graphs; free entries and reused generations
retain their existing semantics. `PhysicalCrossReference` preserves the section,
its lexical `endOffset`, its `stream` flag and the optional hybrid `auxiliary`.
The table remains the owner of `/Prev`; the supplement's entries take precedence.

An initial linearized file has two xref sections but only one complete physical
revision. Its first-page section owns `crossReference`; `linearized` retains the
main section, the optional first-page dummy footer, the linearization dictionary's
source span and its declared `L`. `physicalParts()` returns the exact xref bodies,
including any hybrid supplements. A valid `L` fixes the initial prefix length
inside its actual EOF interval, even when updates append more whitespace.
This groups physical sections; it does not interpret hint streams or certify
optimized network delivery.

## Exact EOF and signed lengths

Each `RevisionBoundary` records five absolute offsets:

| Property | Meaning |
| --- | --- |
| `footerOffset` | First byte of the actual `startxref` keyword, after any indexed trailing objects. |
| `eofOffset` | First `%` of the verified `%%EOF` marker. |
| `eofMarkerEnd` | First byte after the five-byte marker; minimum admissible length. |
| `endOffset` | Conventional prefix end, including the marker's first line ending when present; a linearized initial revision uses its verified `L`. |
| `maximumEndOffset` | First subsequent non-whitespace byte, or the source length. |

`acceptsByteLength($length)` checks the inclusive interval from `eofMarkerEnd`
to `maximumEndOffset`. Only PDF whitespace may lie in that interval. The default
`source()` returns the conventional prefix; `source($length)` returns exactly
that admissible prefix, without trimming, appending or normalizing any byte.
Out-of-interval lengths raise `ParseException`.

A signature verifier must choose the length from the actual `ByteRange`, bind
that exact source and independently validate the range, Contents and CMS. Merely
matching an EOF interval proves none of those things. This library operation
does not choose a signature's signed bytes or create a writer `FileRevision`.

## Raw objects and authentication

`ReadDocument::crossReferences()->entries()` exposes effective entries, including
free and unavailable entries. `objectSpan($identifier)` returns an ordinary
object's `SourceObjectSpan`, from its actual header through `endobj`. It returns
null for compressed members, missing/free objects and wrong generations.
Compressed members have positions inside decoded containers, not independent
source-file spans.

`rawDocument()->sourceRevision()->span($string)` exposes parser-established
string-token provenance, including the delimiters of a signature's `Contents`.
An arbitrary string with the same value does not acquire that provenance.
Signature Contents also retain their original span after a separate authenticated
read. Ordinary decrypted strings are not claims about original plaintext tokens.

Physical inspection deliberately performs no authentication and applies no
source-integrity policy. Raw strings and stream data may be ciphertext;
`permissions()` and `mac()` remain null. Do not interpret that graph as authenticated
content. To interpret a selected prefix, explicitly call a separately configured
`DocumentReader::read($revision->source($exactLength))` with the required password
or recipient capability and integrity policy. Its normal authentication and MAC
checks still run. Encrypted object streams are decoded only in that separate
authenticated graph. Inspection cannot resolve a structural stream length that
itself requires decoding an encrypted object stream.

## Bounds and supported physical organization

All views from one history share one `ReadBudget`: parsed values, xref entries,
decoded data and retained bytes accumulate across revisions. Each retained prefix,
effective index and ordinary-object span is charged, as are additional exact
prefix copies. Cached access to the conventional prefix does not copy it again.
`ReaderLimits::maxRevisions` and all existing parsing/decompression limits apply.
An authenticated `read()` is a separate operation with its own budget; applications
must bound how many such operations they request.

Inspection validates every effective ordinary object's physical extent, including
historical versions, before returning the history. Extents must not overlap each
other, xref bodies or footers. The bytes between known extents must be whitespace
or comments; fake object headers in strings, streams or comments cannot claim an
independent object. Old revisions cannot address future bytes. Extra unindexed
syntax and an appended footer pointing back to an older xref are rejected.

The supported organization is a chronological `/Prev` chain of tables, streams
or hybrid sections. Ordinary indexed objects may follow a primary xref stream
before its footer; their actual extents, including indirect stream lengths, are
validated before locating `startxref`. The initial linearized pair is recognized
from its actual first object and bounded direct parameters. Its forward `/Prev`
must identify the main section exactly, with no further `/Prev`; its final footer
points back to the first-page section. Arbitrary forward chains, unindexed objects
and comments inside the footer are explicitly outside this inspection API. Some are valid PDF layouts;
ordinary `read()` retains its previous behavior. This is a bounded structural
inspection service, not a PDF conformance validator or repair operation.

## Independent validation

`tools/validate_revision_history.py` produces table, stream and hybrid histories,
both clear and AES-256 encrypted, using unmodified pyHanko APIs. Each has an initial
revision, a real CMS approval signature, and a later object update. The hybrid
initial source has an actual companion table and `/XRefStm`; its signature is
created afterwards. A separately assembled initial layout adds indexed objects
after its xref stream, including a stream with an indirect length; unmodified
pyHanko signs and updates that source too. Four initial files are independently
linearized with qpdf (tables/streams, clear/encrypted), then signed and updated by
pyHanko. PyHanko counts their two initial xref sections separately; the oracle maps
them explicitly to one complete physical revision. Five existing pypdf/qpdf fixtures add
deletion and generation reuse. All 42 prefixes from 16 sources pass qpdf without recovery. PyHanko independently
checks xref revision offsets, object resolution and 22 mathematical signature
verifications. Validation is offline with no trusted certificate roots; certificate
trust and authorization of later modifications are deliberately not asserted.

Native tests add forged offsets/footers, overlapping object identities, future
references, raw encrypted access, Contents provenance and cumulative budgets.
CI runs this oracle and the existing checks on PHP 8.3, 8.4 and 8.5. The source
clauses and scope are recorded in [the normative mapping](../resources/revision-history.json).

[Components](components.md) · [Security](security.md) · [Source MAC](source-mac.md)
