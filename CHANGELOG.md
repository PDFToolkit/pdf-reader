# Changelog

English | [Français](CHANGELOG.fr.md)

## Unreleased

## 0.7.0 - 2026-10-28

- Add explicit native-first image decoding with bounded Ghostscript processes and an optional `pdftoolkit/pdf-codecs` PHP fallback. Preserve malformed-input and resource failures; missing capabilities report the Composer installation command without installing anything.
- Expose `ReadDocument::imageData()` with exact component planes, precision, signedness, sampling origins, ICC/colour alternatives and alpha associations. Keep ordinary `streamData()` lossless and explicit when a flat byte representation is unavailable. Resolve filtered/encrypted JBIG2 globals through the same document budgets and cycle guards.
- Keep image codecs unavailable during cross-reference, object-stream and physical-history parsing. Validate qualified native profiles before execution and use PHP for profiles requiring richer geometry, metadata or corrected decoding mechanisms.
- Implement bounded CCITT 1D/mixed/2D decoding, T.4/T.6 uncompressed extensions, explicit damage recovery and exact prefix framing for inline-image consumers. Retain source bytes and configurable cumulative resource limits.
- Add explicitly supplied external stream data using F/FFilter/FDecodeParms; never acquire external paths or URLs implicitly. Correct Identity Crypt parameter handling and physical-revision boundary cases.
- Add atomic, document-scoped graph import with preserved object identity/cycles, explicit encoded/decoded stream policy and XML metadata handling. Reject active source security/signature structures and keep permission/version decisions with the application.
- Require stable core `^0.11 || ^0.12` for the native import contracts. Maintain independent versions, bilingual guides and reproducible PDF/codec validation evidence.

## 0.6.1 - 2026-10-28

- Accept stable core `^0.12` alongside `^0.9`, `^0.10` and `^0.11`, keeping all Reader APIs and resource limits unchanged.
- Preserve stable locked dependencies and verify compatibility against the core 0.12 candidate separately on PHP 8.3, 8.4 and 8.5.
- This maintenance release supports the core development dependency graph before the independent Reader 0.7 feature release.

## 0.6.0 - 2026-10-15

- Add an explicit `StructuralPolicy::Pdf20` for EC3 canonical identifiers, all ObjStm dictionary entry references, recursively direct common xref-stream values and prohibited xref associations; keep historical compatibility as the default. Correct general brace tokenisation and validate both policies against all independent physical-history sources.
- Expose bounded immutable physical revision histories with verified xref/EOF boundaries, exact source prefixes, effective raw graphs, object identities and source spans, including objects placed after a primary xref stream.
- Preserve signature Contents provenance after separate authentication; raw history inspection grants no signature, trust or modification authority. Keep chronological histories distinct from linearized xref arrangements and document their explicit boundaries.
- Share cumulative budgets across all history views and prefix copies. Reject overlapping/hidden objects, future references, forged footers and unattributed syntax.
- Add independent table/stream/hybrid histories, real CMS signatures and AES-256 sources: 12 sources, 30 qpdf-checked prefixes and 14 mathematical signature checks per PHP version. Maintain the existing validations and complete English/French guides.

## 0.5.2 - 2026-10-15

- Correct s3 effective permissions: reserved source bits 9–12 cannot grant later granular rights. Translate the four R2 usage rights into the common document/recipient mask while preserving the original CMS word and explicit subfilter.
- Add 33 native permission cases and ten independent CMS-encrypted s3 sources; retain all previous authentication, MAC, alias and budget validations.
- Accept stable core `^0.11` without changing the published core `0.9.0` validation dependency or PHP requirements.


## 0.5.1 - 2026-10-15

- Accept stable core `^0.10` while retaining the previously supported core series.
- Preserve the published core `0.9.0` validation lock and all dependency versions and source commits; refresh its content hash with Composer.
- Verify the unchanged module against the core 0.10 candidate on PHP 8.3, 8.4 and 8.5, using an isolated validation copy outside production manifests.
- Keep the public API, limits and PHP requirements unchanged; update the documented compatibility in English and French.


## 0.5.0 - 2026-10-15

- Add explicit Adobe.PubSec certificate authentication (s3/s4 RC4; s5 RC4,
  AESV2, AESV3 and AESV4), per-filter keys, deferred EFOpen access, cumulative
  recipient/credential budgets and source PDF MAC with DefaultCryptFilter.
- Return the common DocumentPermissions contract from ReadDocument::permissions().
  Existing Standard ReadPermissions remain available; certificate recipients expose
  separate immutable facts and explicit subfilter, without password-owner claims.
- Add independent pyHanko-produced sources, CMS/key/cipher checks and adverse
  access, packet-order, integrity and budget tests; maintain bilingual guides.

## 0.4.1 - 2026-10-15

- Preserve the terminal encryption-dictionary identity and catalog-owned
  metadata identity through bounded physical reference chains. Reaccessed O/U
  entries stay unchanged; only the actual catalog metadata stream is implicitly
  clear under EncryptMetadata=false. Root aliases use the authenticated resolver.
- Reject alias cycles and wrong generations without extending exemptions to page,
  object or wrongly typed streams. MAC verification still precedes the catalog.
- Keep all previous independent checks and add four encrypted alias cases using
  unchanged strict pyHanko parsing/authentication and cryptography, with explicit
  high-level qpdf/pypdf/pyHanko alias limitations documented in both languages.

## 0.4.0 - 2026-10-15

- Require stable core 0.9 and follow bounded physical reference chains, including
  null terminals, unavailable generations, cycle and depth checks. Optional null
  MAC entries are absent; real values retain TS 32004 directness requirements.

- Verify ISO/TS 32004 source PDF MACs natively before encrypted content is
  interpreted: Standalone, AttachedToSig and document timestamps, Standard R5–R7,
  exact source-token provenance and complete latest-file coverage.
- Add explicit required-integrity policy, bounded CMS and signature padding,
  cryptographic checks through the core verifier and fail-closed malformed,
  missing, stripped or stale MAC handling. No PKI or network operation is implied.
- Exercise 15 independently generated positive and 14 adverse PDF files with
  pyHanko, cryptography, pypdf and qpdf, plus native tampering/budget regressions.
  Document construction, application policy and oracle boundaries in both languages.

## 0.3.2 - 2026-10-15

- Accept stable core `^0.9` while retaining the previously supported core series.
- Preserve the published core `0.7.1` validation lock and all dependency versions and source commits; refresh its content hash with Composer.
- Verify the unchanged module against the actual core 0.9 candidate on PHP 8.3, 8.4 and 8.5, using an explicit validation bootstrap outside production manifests.
- Keep the public API, parsing limits and PHP requirements unchanged; update English and French installation documentation.

## 0.3.1 - 2026-10-15

- Bind `EncryptMetadata=false` to the actual catalog Metadata identity. Page and
  other object metadata continue through their selected crypt filter; an explicit
  Identity filter still leaves the remaining codec input unchanged.
- Resolve indirect stream Type values before selecting EFF versus StmF, including
  encrypted attachments and catalogs stored in object streams.
- Follow table 20's fixed 128-bit V4 key even without global Length; ignore a CF
  entry named Identity before interpreting its supplied value.
- Add seven independent qpdf/cryptography cases and native regressions; document
  source clauses and oracle boundaries in English and French. Explicit credentials,
  shared budgets, original ciphertext and stable runtime dependencies are preserved.

## 0.3.0 - 2026-10-15

- Read hybrid cross-references with auxiliary stream precedence and cumulative bounds.
- Decode LZW EarlyChange 0/1 and TIFF/PNG predictors at 1/2/4/8/16 bits.
- Add explicit Standard password authentication R2–R7, named crypt filters, metadata/signature exemptions and authenticated permissions. Preserve original ciphertext separately from bytes ready for decoding.
- Verify 26 filter cases, six hybrid files and 24 independently encrypted files; retain explicit source PDF MAC, public-key and image-codec boundaries.
- Require stable core ^0.7.1 or ^0.8; PHP 8.3–8.5. Update both documentation languages.


## 0.2.3 - 2026-10-15

- Resolve the effective trailer `/Encrypt` value through the bounded document resolver before raising `EncryptedPdfException`. Direct null, indirect null and unavailable references follow PDF null semantics and do not trigger authentication; malformed non-dictionary values remain parse errors.
- Accept indirect catalog `/Version` names and null values without changing the original file header. Unsupported versions and values with the wrong type remain explicitly rejected.
- Verify optional-value handling with independent traditional and stream cross-references, PDF 1.7/2.0 declarations and catalog fixtures; clarify the authentication boundary in both security guides.

## 0.2.2 - 2026-10-15

- Expose a verified encrypted trailer as `EncryptedPdfException`, retaining compatibility with `UnsupportedPdfFeature` handlers. Applications can dispatch authentication by exception type rather than matching diagnostic text.
- Verify this exception for PDF 1.7 and PDF 2.0 inputs and document the authentication boundary in English and French.

## 0.2.1 - 2026-10-14

- Remove a stale PDF 2.0 rejection sentence from both README editions and align the development branch alias with 0.2.

## 0.2.0 — 2026-10-14

### PDF 2.0 syntax

- Accept PDF 2.0 file headers and `/Version /2.0` catalog overrides through the existing bounded syntax, xref and object-stream readers.
- Preserve explicit rejection of encrypted inputs, unsupported filters and unknown future PDF versions; semantic extraction remains in optional modules.
- Validate three original independent pypdf/qpdf fixtures, including PDF 2.0 object streams and UTF-8 BOM strings.
- Update the English and French scope, architecture and security documentation.

## 0.1.9 — 2026-10-14

### Documentation

- Link the English and French README to the documentation site's stable PDF 1.7 standard download, independently of its location in the core repository.
- Preserve the reader API, runtime behaviour, dependency constraints and locked versions.

## 0.1.8 — 2026-10-14

### Compatibility and scope

- Accepts stable core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7`.
- Locks publication checks to released core `0.6.2`, retaining stable production dependencies and existing development tool versions.
- Preserves the reader API, cumulative limits and PDF 1.0–1.7 input coverage. Core 0.7 writer features do not add PDF 2.0 parsing, decryption or import.
- Requires PHP 8.3 or later; CI validates PHP 8.3, 8.4 and 8.5 and the independent PDF oracles.

## 0.1.7 — 2026-10-14

### Compatibility

- Accepts stable core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6`.
- Locks release validation to published core `0.5.5` without changing development tool versions or commits.
- Checks the unchanged module against the core 0.6 candidate on PHP 8.3, 8.4 and 8.5.
- Preserves the reader API, limits and PDF 1.0–1.7 input coverage; PDF 2.0 reading is not added.

## 0.1.6 — 2026-10-14

### Bilingual documentation

- Makes English the default language for the README, guides and complete release history.
- Adds complete French counterparts with reciprocal language links and French documentation entrypoints.
- Translates explanatory example comments while preserving executable tokens and output data.
- Locks validation to published core 0.5.4 without changing other dependency versions or commits.
- Preserves API identifiers, original fixtures and runtime behaviour.
## 0.1.5 — 2026-10-14

### Installation and publication

- Publishes the package on Packagist and reduces installation to one
  `composer require` command in an application.
- Uses default Composer repositories and connects package metadata to its
  documentation at `https://pdftoolkit.github.io/`.
- Locks checks to stable core `roukmoute/pdf:0.5.3` without changing development
  tool versions.
- Verifies indexing of published versions and installation without VCS or
  local repositories. Preserves the PHP API, existing mechanisms and limits.

## 0.1.4 — 2026-10-14

### Documentation

- Connects shared documentation to `https://pdftoolkit.github.io/` and adds
  the stable reader entrypoint at `/modules/reader/`.
- Entrusts shared guides and site publication to
  `PDFToolkit/pdftoolkit.github.io`, with module sources pinned to their
  published versions and exact commits.
- Corrects README compatibility information to reflect the existing
  `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5` constraint.
- Preserves the API, reading mechanisms, dependencies and their locked versions;
  this release changes documentation only.

## 0.1.3 — 2026-10-13

### Compatibility

- Accepts stable core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5`.
- Locks checks to published core `0.4.0` under PHP 8.3, 8.4 and 8.5.
- Preserves the reader API and limits; no additional reading mechanism
  is added by this compatibility patch.

## 0.1.2 — 2026-10-13

### Compatibility

- Accepts stable core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4`.
- Preserves the module API and external-data processing boundary.
- Checks stable core 0.3.0 and new core 0.4 objects in development
  under PHP 8.3, 8.4 and 8.5.

## 0.1.1 — 2026-10-13

### Compatibility

- Accepts core `roukmoute/pdf:^0.2 || ^0.3`, allowing installation with new
  PDF objects in the 0.3 series without changing the module API.
- Revalidates tests and independent PDFs with stable core `0.2.1`.
  No reading or conversion mechanism is added by this patch.
## 0.1.0 — 2026-10-13

First implementation of the external PDF reader, under `PDF\Reading`, in
`pdftoolkit/pdf-reader`. The reader depends on shared model `roukmoute/pdf:^0.2`;
no reverse dependency is added to the model.

### Added

- Structural reading of PDF 1.0 to 1.7 using the same object primitives.
- Bounded syntax parser, indirect objects and streams with direct or indirect lengths.
- Traditional xref tables, `/Prev` revisions, free entries and generations.
- Xref streams and compressed `/ObjStm` objects, with explicit resolution and document caches.
- Bounded page-tree traversal, checking counts, parents and identities.
- Immutable read-page objects and effective attributes: resources, MediaBox, CropBox and rotation.
- Ordered reading of a page's content streams, without implicit concatenation or decoding.
- Flate, ASCIIHex, ASCII85 and RunLength decoding; TIFF and PNG predictors with 8-bit components.
- Configurable limits, cumulative budgets and structural or unsupported-feature diagnostics.
- Five original fixtures generated with pypdf and qpdf, independently of the PHP writer.
- Two independent page-inheritance fixtures, with traditional xref and compressed objects.
- Checks on PHP 8.3, 8.4 and 8.5; component and security-boundary documentation.

Hybrid references, encryption, PDF 2.0, import into the writer, rewriting,
text extraction and rendering are not implemented yet.
