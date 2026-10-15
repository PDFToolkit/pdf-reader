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

`EncodedStream::encodedBytes()` returns decrypted bytes ready for the remaining
codecs; `originalEncodedBytes()` retains the original source ciphertext. Retained
plaintext consumes the shared document budget. Import and writing still require
an explicit remapping layer; authenticated source objects cannot be serialized
directly into a new document.

Source PDF MAC verification (`KDFSalt`/`AuthCode`) and public-key authentication
are not implemented by this password capability. Such sources are explicitly
rejected before protected content is interpreted. Unsupported image codecs
remain separate from decryption. Successful authentication does not certify
that an original PDF is safe to execute in another application.

Independent validation uses 24 actual R2–R7 PDFs: qpdf creates R2–R6 and Python
cryptography independently creates AESV4 payloads using qpdf's R6 password
entries. Both passwords, binary strings, object streams, metadata settings and
signature exemptions are checked. These synthetic credentials are public test
data. See [security controls](security.md) and [components](components.md).
