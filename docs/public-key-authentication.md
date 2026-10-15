# Read certificate-encrypted PDFs

English | [Français](fr/public-key-authentication.md)

`PublicKeyAuthentication` implements the Adobe.PubSec handler using an explicitly
supplied core `RecipientDecrypter`. It receives PDF bytes from the application,
recovers the seed from a matching CMS envelope and authenticates default document
filters before the catalog is interpreted. It does not discover keys, fetch
certificates or establish PKI trust.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\PublicKeyAuthenticationLimits;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\RecipientDecrypter;

/** @var RecipientDecrypter $recipientDecrypter Application-selected identity. */
$authentication = new PublicKeyAuthentication(
    $recipientDecrypter,
    PublicKeyPermissionByteOrder::MostSignificantFirst,
    new PublicKeyAuthenticationLimits(maxCredentialAttempts: 64),
);
$document = (new DocumentReader(authentication: $authentication))->read(new FileBytes($bytes));
$permissions = $document->permissions();
$effectiveMask = $permissions?->effectiveMask();
```

For local PEM credentials, construct core `OpenSslIdentity` from an explicit
`CertificateChain`, private-key PEM bytes and optional passphrase, then pass it to
`OpenSslRecipientDecrypter`. That backend requires `ext-openssl` and temporary
files in an application-selected directory; it invokes no executable. Alternative
CMS capabilities remain application choices. A nonempty list of decrypters may
be supplied for separate document and attachment identities. Recipients are tried
in original source order, then identities in application order. A malformed
matching payload fails instead of falling back to a more permissive recipient.

Run the application-level example with an explicit PDF, DER certificate and
unencrypted PEM private key (or adapt it to supply a passphrase securely):

```sh
php examples/certificate.php document.pdf certificate.der private-key.pem
```

It reports page count, effective permissions and the verified MAC location.

| Source mechanism | Supported operation |
| --- | --- |
| s3/s4, V1/V2 | Historical RC4; s3 and V1 use 40 bits. |
| s5, V4 | Named V2/RC4 and AESV2/AES-128 filters. |
| s5, V5/V6 | AESV3/AES-256 CBC and AESV4/GCM respectively, including source PDF MAC. |
| Identity | Standard pass-through filter; source definitions cannot override it. |

Public-key CF `Length` is measured in **bits**, unlike Standard CF byte lengths.
Absent lengths use historical defaults (AESV2 128, AESV3/AESV4 256, RC4 the global
Length or 40); this is compatible reading, not an ISO2 conformance claim.
Custom handlers, unknown cipher methods and CFM None remain unsupported. None
delegates decryption to a handler; it is not silently treated as Identity.

## Recipient facts and permissions

Document-scope CMS plaintext is exactly 20 seed bytes plus four permission bytes.
An individual-stream envelope contains exactly the 20 seed bytes. Keys are derived
from that recovered seed and **all original CMS packets in their original order**,
including packets for other recipients. A historical compatibility `Encrypt.P`
does not grant rights.

The permission byte order is a required constructor argument:
`MostSignificantFirst` follows deployed/corrected ISO2 semantics;
`IsoLeastSignificantFirst` selects the literal ISO2008 wording. No heuristic
switches between them. Public-key bit 2 grants all usage rights but does not
represent a Standard password-owner login.

`ReadDocument::permissions()` now returns `?DocumentPermissions`, a common
`effectiveMask()` contract. Standard authentication still returns the existing
`ReadPermissions` with `mask`, `access` and `revision`. Certificate authentication
returns `PublicKeyReadPermissions`; its `filters` map exposes
`PublicKeyRecipientAccess` objects containing the authenticated permission word
and first matching `recipientIndex`, without key material. Where default filters
have different permission words, `effectiveMask()` conservatively intersects
their rights. Individual-stream access does not grant extra document rights.
Applications needing password-specific fields must check `instanceof ReadPermissions`.
The typed `subFilter` preserves s3/s4/s5 identity. For s3, only the historical
R2 usage bits 3–6 are meaningful; reserved bits must not be interpreted as the
later granular permissions. Starting with 0.5.2, both document and recipient
`effectiveMask()` translate these four broad R2 rights into the common mask:
form filling follows bit 6, accessibility extraction follows bit 5, assembly
follows bit 4 and unrestricted printing follows bit 3. Source bits 9–12 never
grant those rights for s3. The original authenticated word remains available in
`PublicKeyRecipientAccess::permissions`; its `subFilter` also remains explicit.
Bit 2 still grants all PubSec permissions. For s4/s5, the granular source bits
retain their normal meanings. Applications should enforce the effective mask,
not reinterpret a raw s3 permission word as an s4/s5 word.

## Independent filters and deferred access

Each named filter has its own recovered key and recipient facts. DocOpen filters
authenticate when opening. EFOpen filters authenticate only when their protected
stream is accessed; missing attachment credentials do not prevent an otherwise
accessible document from opening. If an EFOpen filter is selected by StrF or StmF,
it is treated as DocOpen as required by table 25. There is no fallback to another
filter key or to Identity. Explicit Crypt uses the direct filter key; ordinary
historical encryption uses the owning object number and generation.

Catalog metadata ownership, terminal reference identities, signature/XRef
exemptions and single decryption of object-stream members use the same bounded
pipeline as Standard authentication. Metadata flags come from the stream filter,
and only actual catalog metadata can receive the implicit cleartext exemption.

For modern PubSec, any indicated PDF MAC is verified before catalog interpretation
using the authenticated **DefaultCryptFilter** key. A document-scope
DefaultCryptFilter is authenticated at open even if declared EFOpen, so its
required-MAC bit cannot be hidden behind deferred access. Other selectors cannot
substitute their keys. Authenticated bit 13, direct KDFSalt, latest physical
AuthCode and the required-integrity policy retain their existing enforcement.
Use `SourceIntegrityPolicy::RequirePdfMac` when integrity is expected; see the
[source MAC guide](source-mac.md). Neither CMS decryption nor a valid MAC grants
signature trust, revocation status or authorization to change protected revisions.

## Bounds and validation

`PublicKeyAuthenticationLimits` defaults to 64 filters, 1,024 total recipient
packets, 1 MiB per packet, 16 MiB total recipient bytes, 4,096 credential attempts
and 32 application identities. Counts, bytes and attempts are cumulative across
filters, including deferred access, and reset for each document read. General
syntax, source, retention and decoding limits also apply. `PublicKeyAuthenticationFailed`
reports no matching credential without exposing certificate, key or source data.
Malformed dictionaries/payloads and exhausted budgets raise `ParseException`.

The independent oracle `tools/validate_public_key_authentication.py` generates
real sources with unmodified pyHanko and checks PHP results against independent
CMS/key/cipher operations. Its 47 sources include 14 s3 cases; ten pair each
broad R2 right with source bits 9–12 clear or set. pyHanko supplies the real CMS
and encrypted bytes, while the oracle applies the normative s3 permission
interpretation independently of pyHanko's common flag enum. Native adverse tests separately cover packet order,
scope, first matching rights, unknown filters, delayed failures and limits.
The existing Standard, metadata, hybrid and MAC oracles remain required.

Normative sources retained unchanged in the core: ISO 32000-2:2020 §§7.6.5–7.6.6
(physical pages 101–108), ISO 32000-1:2008 §7.6.4 (pages 72–77), Adobe supplement
AESV3 (page 21), ISO/TS 32004:2024 §6.4 (page 15). [The source map](../resources/public-key-authentication.json) records their SHA-256
identities; this implementation reuses the core's documented derivation and cipher
primitives rather than adding another cryptographic implementation.
