# Authenticate a source PDF MAC

English | [Français](fr/source-mac.md)

Reader 0.4 uses the core 0.9 verifier for **ISO/TS 32004:2024** PDF MACs.
An explicitly selected `StandardPasswordAuthentication` authenticates the
password and encrypted permissions, verifies any indicated MAC against the
original file bytes, then permits the catalog and protected content to be read.
A corrupt, missing required, unsupported or stale MAC is an error, with no
fallback to unauthenticated decryption.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\SourcePdfMacVerification;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;

$authentication = new StandardPasswordAuthentication(
    new PreparedPassword($preparedUtf8Password),
    mac: new SourcePdfMacVerification(maximumTokenBytes: 256 * 1024),
);
$reader = new DocumentReader(
    authentication: $authentication,
    sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac,
);
$document = $reader->read(new FileBytes($bytes));
// Standalone or AttachedToSignature; this is not a signature-trust result.
$location = $document->mac();
$permissions = $document->permissions();
```

`AcceptUnprotected`, the compatible default policy, also reads unprotected PDFs.
It still verifies every MAC indicated by `AuthCode`, `KDFSalt` or authenticated
permission bit 13. `RequirePdfMac` additionally rejects an unprotected source,
including one from which all encryption and MAC indications have been removed.
An ordinary clear PDF cannot reveal that such indications once existed.
Choose the required policy whenever the application expects protected input.

## Exact source coverage

The parser records the lexical source span of the actual security string,
including its delimiters. Two equal strings in different objects or revisions
remain distinct. Decoded object-stream values do not acquire a span in the
original file. No search for similar token bytes is used as proof.

| Location | Verified source binding |
| --- | --- |
| `Standalone` | The latest physical trailer contains a direct AuthCode dictionary. Its direct four-integer ByteRange covers the whole current file except its actual direct hexadecimal MAC token. Only complete hexadecimal bytes are accepted: no internal whitespace, odd final nibble, extra excluded bytes or CMS padding. SigObjRef must be absent. |
| `AttachedToSig` | AuthCode contains an indirect SigObjRef and no MAC or ByteRange. The referenced signature dictionary's direct ByteRange excludes exactly its actual direct hexadecimal Contents token. One DER CMS object is accepted with only trailing zero signature padding. Exactly one PDF MAC unsigned attribute and value bind both the file bytes and the raw SignerInfo signature octets. Digital signatures and document timestamps are supported. |

An older trailer's inherited AuthCode cannot authenticate a new revision. The
latest MAC is checked against the entire current source, including older
revisions; this follows Annex B. The reader does not independently validate each
historical revision's former MAC. Appending even whitespace makes a MAC stale.
Table, stream and hybrid cross-reference handling retains its ordinary bounds.
MAC verification occurs before any encrypted catalog or content is interpreted.

## Cryptographic boundary

Native password authentication supports Standard R5/V5, R6/V5 and R7/V6, with a
direct 32-byte KDFSalt. The MAC verifier uses the actual authenticated file key:
HKDF-SHA256 with `PDFMAC`, AES-256 key unwrapping and HMAC-SHA256. It checks CMS
AuthenticatedData, its recipient, required attributes, optional algorithm
protection and document/signature digests. SHA-256/384/512 and
SHA3-256/384/512 are supported. SHA-2 algorithm identifiers accept absent or NULL
parameters according to RFC 5754; other parameters remain constrained.

This authenticates the file using its encryption key. It does **not** verify the
underlying signature mathematically, establish certificate trust, check a TSA,
fetch certificates or revocation data, or grant additional document permissions.
Anyone with the file encryption key, including a valid user-password holder,
can produce a new MAC. An empty user password offers no additional protection
against someone who can derive that key. Successful MAC authentication does
not make PDF actions or attachments safe to execute.

Public-key source authentication, unknown MAC locations and other CMS wrapping
or MAC algorithms are explicitly unsupported. Attached payloads currently
require direct hexadecimal Contents and ByteRange, with an uncompressed source
signature object. This is a bounded source reader, not a complete PDF conformance
validator. A custom `DocumentAuthentication` is a trusted application capability;
its supplied decryption context must preserve the same verification boundary.

## Limits and independent checks

`SourcePdfMacVerification` defaults to a 1 MiB CMS limit, including the allocated
Contents and its padding for attached signatures. The source token must also fit
`ReaderLimits::maxTokenBytes`; hexadecimal encoding uses two characters per
decoded byte. Source spans, the covered-byte buffer, plaintext and decoded
streams consume the document's cumulative retained/decoded budgets. The source
file limit is checked before authentication. These limits do not promise an
exact maximum PHP process-memory size.

The checked-in [fixtures](../tests/Fixtures/pdf-mac/README.md) and
`tools/validate_source_mac.py` cover 15 real files: six digest algorithms, both
MAC locations, a document timestamp, object streams, incremental updates and
R5–R7 encryption. pyHanko and cryptography independently validate CMS/HKDF/AES
key wrapping/HMAC/digests; qpdf checks the 13 non-R7 files without warnings.
qpdf 12.4.2 does not support AESV4 and pyHanko does not authenticate historical R5;
R7 uses unchanged pyHanko/cryptography, and R5 uses pypdf/qpdf credentials plus
cryptography and pyHanko's standalone token validator. No oracle is patched.

Fourteen independently generated adversarial files include authentic MACs with
incorrect source framing or semantic claims, duplicate/missing CMS attributes,
wrong signature binding and inherited stale MACs. Native unit tests also modify
source comments, ciphertext-independent bytes, required markers, permissions,
signature padding and final file length. Their refusal is tested before a
`ReadDocument` is returned.

Sources: ISO/TS 32004:2024, Tables 2–6 (physical pages 10–12), §§6.2–6.6
(pages 13–17), Annex B (pages 19–20); the original is retained in
[the core reference documents](https://github.com/PDFToolkit/pdf/tree/master/resources/reference-documents).
[RFC 5754 §2](https://www.rfc-editor.org/rfc/rfc5754#section-2) and
[RFC 6211 §3.2](https://www.rfc-editor.org/rfc/rfc6211#section-3.2) define
algorithm-identifier compatibility.

[Password authentication](authentication.md) · [Security controls](security.md)
