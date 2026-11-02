# Inspect PDF structure

English | [Français](fr/inspection.md)

Reader 0.8 exposes an immutable inspection snapshot of the **parsed source PDF**.
It does not derive HTML, replace content with associated HTML/MathML, modify the
PDF, validate accessibility conformance, or certify signatures. This distinction
lets a viewer display the actual tags even when a renderer uses replacement content.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\Inspection\InspectionLimits;

$document = (new DocumentReader())->read(new FileBytes($pdfBytes));
$inspection = (new DocumentInspector(new InspectionLimits(
    maxDepth: 48,
    maxNodes: 5000,
    maxPages: 100,
    maxObjects: 2000,
    maxValues: 20000,
    maxBytes: 2097152,
    maxStringBytes: 4096,
    maxAssociations: 1000,
    maxDiagnostics: 100,
)))->inspect($document);
$json = json_encode($inspection, JSON_THROW_ON_ERROR);
```

The application supplies bytes, authenticates encrypted PDFs using the existing
reader APIs and enforces `ReadDocument::permissions()`. Inspection neither grants
access nor bypasses authentication. `authenticatedPermissions` is the verified
effective mask, or `null` for an unencrypted document; it is not a signature result.
Display all source-controlled strings as text, never as executable markup or URLs.

## Snapshot contract (schemaVersion 1)

`DocumentInspection` is `readonly` and implements `JsonSerializable`. Its public
properties correspond to these JSON members. Lists retain source order.

| Member | Shape |
| --- | --- |
| `document` | `version`, `tagged`, `catalogReference`, `catalog`, `structureRootReference`, `structureRoot`, `authenticatedPermissions` |
| `pages` | List of `{number, reference, properties}`; page numbers are one-based |
| `roots` | Ordered root node IDs |
| `nodes` | Flat ordered node records described below |
| `objects` | `{id, reference, status, value}` for explicitly bounded reference expansion |
| `roleMappings` | `{owner, namespace, source, target}` preserving original mapping declarations |
| `associatedFiles` | `{id, owner, reference, filename, relationship, properties, embeddedStreams}` |
| `diagnostics` | `{code, message, nodeId, reference}`; nullable context, fixed messages |
| `truncated` | Whether limits prevented complete inspection, including page or diagnostic limits |

A reference is `{"number":"12","generation":0}`. Object numbers and integer values
are decimal **strings**, preserving values beyond JavaScript's safe integer range.
`catalog`, `structureRoot`, page properties, node properties and object values use
the typed representation below. A missing structure root is `null`; `tagged:false`
is a valid inspectable PDF, not an HTML derivation error.

A node contains `id`, `parentId`, `kind`, `reference`, `role`, `namespace`, `page`,
`mcid`, `objectReference`, `properties` and `children`. IDs such as `node-1` are
snapshot-local. `kind` is `element`, `mcid`, `mcr`, `objr`, `invalid`, `cycle` or
`repeated`; `children` contains ordered IDs. `role` is a typed name. `namespace`
is `{identity, uri, reference}` or `null`; two namespace objects with the same URI
keep separate identities. `page` is `{number, reference, inherited}` or `null`.
An unresolved page has `number:null`. Inheritance affects this display field only:
no `/Pg` is added to original properties. `mcid` is a decimal string or `null`.
`objectReference` retains an OBJR's original `/Obj` reference.

Mapping `owner` is `structure-root` or a namespace identity. `source` is a typed
name and `target` is its original typed value, including namespace arrays. Mapping
chains are not interpreted as HTML roles. A missing RoleMap for standard tags is
normal. Namespaces and mappings are available through original dictionaries too.

An associated-file `owner` is `{kind,id,reference}`, where kind is `catalog`, `page`,
`structure` or `embedded-files`. The same file may legitimately have several owners.
`filename` and `relationship` are optional display labels; their original bytes
remain in `properties`. Each embedded stream has `{key,reference,properties,
encodedByteLength}`; `key` is a typed name. No file contents are decoded, downloaded,
or executed. Catalog/page/element `/AF` and the `/Names/EmbeddedFiles` name tree are
listed; arbitrary attachment references remain available in the object graph.

### Typed values

| `type` | Additional fields |
| --- | --- |
| `null`, `boolean`, `integer`, `real` | `value` (null, boolean, decimal string, parsed number respectively) |
| `name` | `hex`, `byteLength`, `preview`, `truncated` |
| `string` | `hex`, `byteLength`, `preview`, `truncated`, `syntax` (`literal` or `hexadecimal`) |
| `reference` | `reference`, `target`, `status` (`queued` or `omitted`) |
| `array` | `items`, `truncated` |
| `dictionary` | `entries:[{key,value}]`, `truncated`; keys are typed names |
| `stream` | `dictionary`, `encodedByteLength`; no payload |
| `truncated` | `reason` |
| `unsupported` | No additional fields |

`hex` preserves parsed name/string bytes; when truncated it is explicitly a prefix
and `byteLength` retains the original length. Names use PDF `/Name#XX` notation for
their preview, without guessing an encoding. String previews interpret PDF text
encoding according to the effective PDF version; this is a convenience, since a
PDF byte string is not necessarily text. Invalid encoding, a truncated string or
unavailable UTF16 support yields a null preview; exact bytes remain available.
A preview may contain control characters: UI code must display them safely.

For example, an original `/A 12 0 R` dictionary entry remains a reference:

```json
{"key":{"type":"name","hex":"41","byteLength":1,"preview":"/A","truncated":false},"value":{"type":"reference","reference":{"number":"12","generation":0},"target":"obj-12-0","status":"queued"}}
```

Find `obj-12-0` in `objects` to inspect its separately expanded value. Status there
is `available`, `missing`, `failed` or `omitted`. Missing/free/different-generation
references are distinguished from a genuine null object. A reference omitted by
the queue budget has `target:null,status:"omitted"`; never assume every reference
has an expanded row. Physical reference chains keep one row per object. Object IDs
are document-local; direct identities such as `direct-1` are snapshot-local.

“Original” means parsed PDF values before content derivation. It does not mean
byte-for-byte lexical syntax: whitespace, alternate escapes, number spelling and
dictionary layout are not reconstructed. Real numbers have the parser's numeric
precision. Encryption has already been authenticated/decrypted by Reader.

## Bounds and diagnostics

`InspectionLimits` defaults to depth 48, 5000 nodes, 5000 pages, 2000 objects,
50000 values, 2097152 accounted bytes, 2048 bytes per string/name, 2000 associations
and 100 diagnostics. All limits must be positive. Budgets are cumulative across
properties, mapping labels, file metadata and expanded objects, including repeated
presentations of the same dictionary. The byte budget conservatively accounts for
variable presentation data and record overhead; it is not a cap on PHP heap usage,
JSON pretty-print whitespace or the fixed result envelope. An application may also
cap the final encoded JSON size. ReaderLimits continue to bound parsing separately.

Traversal follows `/K`, never parent links. Cycles, repeated elements and malformed
branches are retained as diagnostic rows; unrelated branches remain inspectable.
The page tree is validated separately. If it cannot be validated, page results are
empty, available structure properties remain visible, and `truncated` is true.
Limit failures return an explicit partial snapshot instead of pretending success.
Structural data is inspected before general object expansion, so large resources
do not take priority over tags. This is an inspection aid, not a complete semantic
conformance validator: source properties remain authoritative even when malformed.

No page contents, images or attachment streams are decoded by the inspector.
Reader may still decompress object streams needed to parse dictionaries, under its
own existing limits. Source opening, crypto authentication and unrecoverable parser
errors may still throw before inspection begins. Results are never shared between
documents, and repeated inspections do not mutate the source graph.

[Components](components.md) · [Security](security.md) · [Pages](pages.md)
