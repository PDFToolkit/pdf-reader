# Independent public-key authentication fixtures

[English](README.md) · [Français](README.fr.md)

`tools/validate_public_key_authentication.py` generates synthetic Adobe.PubSec
PDFs with pinned pyHanko 0.47.0 and cryptography 50.0.2, then reads them with the
native PHP reader. Install `tools/source-mac-validation-requirements.txt` with
`pip --require-hashes`; these libraries are development-only oracle inputs.

```sh
python tools/validate_public_key_authentication.py --php php
```

Use `--work-dir _build/public-key` to retain PDFs, a SHA-256 manifest and generated
test credentials. Use `--fixtures-dir _build/public-key` to validate those same
bytes again. The standalone producer is `tools/generate_public_key_fixtures.py`.
Certificates are self-signed test identities; no certificate trust, signature
validation or network operation is claimed. Random keys, seeds, IVs and timestamps
make bytes differ between runs; the cases and assertions are reproducible.

The 47 positive cases cover s3/s4 RC4, s5 RC4/AESV2/AESV3/AESV4, both states of
permission bit 2, an unmatched first CMS packet followed by a matching second
packet, object streams, a hybrid xref, an incremental revision, clear catalogue
metadata, distinct document/EFOpen keys with explicit Crypt filters, Identity,
and standalone/signature-attached MACs. The oracle independently decrypts CMS
RSA/AES envelopes with cryptography, checks the big-endian permission words and
recipient-array key derivation, compares clear strings/streams/metadata, and
checks MAC digests, HKDF, key wrapping and HMAC. It also requires 47 wrong-identity
refusals, five modified-MAC refusals and refusal of a MAC authenticated with the
wrong filter key.

Ten additional real S3 sources pair each broad R2 permission with source bits
9–12 clear or set. The PHP effective mask must derive granular rights only from
R2 bits 3–6, preserving the original CMS word separately. pyHanko checks CMS and
ciphertext; its shared permission enum is not an oracle for S3 interpretation.

One MAC case deliberately uses a DefaultCryptFilter key different from StmF/StrF.
pyHanko's global MAC accessor chooses StmF, so that case uses its unchanged
low-level MAC/CMS components with the explicit DefaultCryptFilter key and the
independent cryptography oracle. The opposite control is accepted by pyHanko's
global validator with the StmF key and must be rejected by the PHP reader.
Hybrid input is parsed by pyHanko in non-strict mode because its strict mode
intentionally rejects hybrid xrefs. No dependency is monkeypatched.

These sources test authentication and bounded reading, not certificate trust,
complete PDF conformance or graphical rendering. The ISO little-endian permission option is covered by native unit tests,
not by these independent CMS fixtures. The test-only private keys must
never be used for application data.

A second oracle, `tools/validate_public_key_aliases.py`, covers four AES-128/256
metadata cases with direct references and three-hop Encrypt/Root/Metadata aliases.
It compares unmodified strict pyHanko parsing and cryptography with native PHP,
including encrypted page/Form metadata and unchanged original CMS/ciphertext.
Two additional cases append an implicit EFF attachment under a distinct AES-128
or AES-256 key. Object-number derivation and deferred missing-identity failures
are independently checked; no explicit Crypt filter is used in those streams.

```sh
python tools/validate_public_key_aliases.py --php php
```
