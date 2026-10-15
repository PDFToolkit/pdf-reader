# Independent password-encryption fixtures

English | [Français](README.fr.md)

These six original MIT PDFs were encrypted by qpdf 12.4.2 and copied unchanged
from the core's `tests/Fixtures/adobe-encryption` fixtures. The public test
passwords are `user` and `owner`. `manifest.json` records their exact bytes and
SHA-256 identities. The first four exercise ISO revisions 2/3/4 and the last
two exercise the Adobe supplement's historical revision 5.

`tools/validate_authentication.py` independently creates another 20 encrypted
PDFs with qpdf during CI: RC4/AES, R2–R6, both password classes, metadata
exemptions, compressed objects and signature Contents exemptions. The native
reader must recover the source binary strings, page content and XMP exactly.
No fixture credential is an application secret. These historical algorithms
are compatibility tests, never selected implicitly for new encryption.
