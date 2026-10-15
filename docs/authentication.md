# Read password-protected PDFs

English | [Français](fr/authentication.md)

Authentication is an explicit capability of one `DocumentReader`. Without it,
an effective encrypted trailer raises `EncryptedPdfException` before the catalog
is interpreted. The library never discovers passwords or opens external files.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;

// The application supplies these bytes and prepares the UTF-8 password.
$authentication = new StandardPasswordAuthentication(new PreparedPassword($password));
$reader = new DocumentReader(authentication: $authentication);
$document = $reader->read(new FileBytes($bytes));
$permissions = $document->permissions();
```

`PreparedPassword` represents already prepared UTF-8 input for R5–R7; choosing
Unicode preparation is an explicit application step. For historical R2–R4 use
`new PdfDocEncodedPassword(new HexadecimalString($pdfDocEncodedBytes))` instead.
Use `PDF\Security\PdfDocEncodedPassword` and
`PDF\Syntax\Object\StringObject\HexadecimalString`. These bytes are not UTF-8.
The default AES backends require OpenSSL. Alternative core AES backends can be
injected in the authentication constructor; no executable is invoked at runtime.

| Source mechanism | Native operation |
| --- | --- |
| R2 / V1 | RC4, 40-bit file key. |
| R3 / V2 | RC4, variable historical key length. |
| R4 / V4 | Named None, V2 and AESV2 filters; string, stream and embedded-file defaults. |
| R5 / V5 | Historical AES-256 password authentication and encrypted permissions. |
| R6 / V5 | Modern password hashing, AESV3 and authenticated permissions. |
| R7 / V6 | AESV4 authenticated GCM payloads; a modified tag is rejected. |

`ReadPermissions` retains the source `mask`, authentication `access` (User or
Owner) and `revision`. `effectiveMask()` returns the source mask for a user and
all document permissions for an owner. The application decides how to present
and enforce usage rights. This result is not a digital-signature validation.
`AuthenticationFailed` identifies an incorrect password without including it
in the message; malformed dictionaries or encrypted data raise `ParseException`.

The authenticated document decrypts strings and streams once, using the owning
object's key where required. An explicit first `Crypt` filter is consumed before
the remaining codec chain. Xref streams, the encryption dictionary and signature
`Contents` are exempt. Object-stream members are parsed after the container is
decrypted and are not decrypted twice. Clear metadata and embedded-file filter
selection follow their dictionary settings.

### Metadata ownership and indirect stream types

Version 0.3.1 binds `EncryptMetadata=false` to the actual `Metadata` reference of
the effective catalog. A page or Form's `/Type /Metadata` stream remains encrypted
unless it explicitly selects Identity. Indirect `Type` names are resolved before
choosing `EFF` versus `StmF`. Catalogs in encrypted object streams retain these
rules without recursively loading their metadata while being decrypted.

Native authentication supplies the immutable document-root reference. A custom
`DocumentAuthentication` constructing `DocumentDecryption` can supply that last
optional `documentRoot` argument too; without it, no implicit catalog-metadata
exemption is guessed. Explicit `Crypt /Identity` remains available independently.
Source: ISO 32000-2:2020, §7.3.10 (physical page 49), `EncryptMetadata` (page 94)
and distinct page metadata (page 121).

Table 20 (physical page 88) fixes the V4 file key at 128 bits; the optional
global Length/default 40 belongs to V2/V3. A standard-name entry such as Identity
inside CF is ignored before interpreting its value and cannot redefine that
standard filter. Its actual identity behaviour remains available.

`EncodedStream::encodedBytes()` returns decrypted bytes ready for the remaining
codecs; `originalEncodedBytes()` retains the original source ciphertext. Retained
plaintext consumes the shared document budget. Import and writing still require
an explicit remapping layer; authenticated source objects cannot be serialized
directly into a new document.

Source PDF MAC verification (`KDFSalt`/`AuthCode`) is performed before protected
content is interpreted, for Standard R5–R7. See the [PDF MAC guide](source-mac.md)
for both locations, exact byte coverage, required-integrity policy and boundaries.
Public-key authentication remains explicitly unsupported. Unsupported image codecs
remain separate from decryption. Successful authentication does not certify
that an original PDF is safe to execute in another application.

Independent validation uses 24 actual R2–R7 PDFs: qpdf creates R2–R6 and Python
cryptography independently creates AESV4 payloads using qpdf's R6 password
entries. Both passwords, binary strings, object streams, metadata settings and
signature exemptions are checked. These synthetic credentials are public test
data. Seven additional cases verify catalog/page/Form metadata, explicit Identity,
the fixed V4 key without global Length, ignored standard CF entries
and indirect embedded-file types, with ordinary and compressed catalogs. qpdf
independently decrypts the metadata files; cryptography constructs EFF payloads
with qpdf credentials. qpdf 12.4.2 does not decrypt EFF when StmF is Identity,
even with a direct Type, so its decryption is not used as an oracle for that
particular selection. Its algorithms are never patched for the test.

Reader 0.4.1 preserves the terminal object number and generation through Encrypt,
Root and catalog Metadata aliases. Reaccessing the encryption dictionary does
not decrypt its O/U credential entries. Only the actual, correctly typed catalog
metadata stream is implicitly clear; page and object metadata keep their cipher.
Identity traversal uses the same bounded authenticated resolver and avoids
recursively loading the stream currently being processed. MAC verification still
precedes catalog interpretation.

Four additional alias files preserve the original encrypted payload bytes.
qpdf 12.4.2, pypdf 6.20.1 and pyHanko 0.37 high-level decryptors do not follow
Encrypt reference chains. Their previous source checks remain unchanged. The
additional oracle uses pyHanko's unchanged strict low-level parser and actual
Standard handler on the terminal dictionary, then cryptography AES-CBC/PKCS7
with each stream's real object number/generation. It compares catalog, page,
object and Identity payloads and unchanged O/U bytes, without rewriting input or
patching any authentication algorithm.
CI pins checksum-verified qpdf 12.4.2; older qpdf versions can emit non-catalog
metadata in cleartext under the document-only metadata setting.
qpdf 12.4.2 also requires global Length for V4; the omitted-key case preserves
all offsets and cipher bytes from the independently verified source, following
table 20's fixed 128-bit contract. See
[security controls](security.md) and [components](components.md).
