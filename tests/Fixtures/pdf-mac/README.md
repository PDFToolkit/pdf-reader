# Independent source PDF MAC fixtures

English | [Français](README.fr.md)

The fifteen PDFs are original synthetic tests, independent of the PDFToolkit
writer. `manifest.json` freezes their SHA-256 and expected MAC location. Public
test passwords are `user` and `owner`; ephemeral signing keys were discarded.
The included certificates establish no PKI or timestamp trust.

- pyHanko 0.37.0 creates fourteen PDFs: six digest algorithms, xref tables and
  streams, a compressed object, an incremental revision, signatures and a
  document timestamp, including R6 and R7 encryption.
- The R5 file preserves the credentials of the existing independently generated
  qpdf R5 fixture. pypdf recovers its public test key; cryptography writes the
  actual R5 CBC payloads and encrypted MAC-required permissions. pyHanko creates
  the standalone CMS token without overriding its security-handler algorithms.
- `tools/validate_source_mac.py` checks CMS, exact source/signature digests,
  HKDF, AES key wrapping and HMAC using pyHanko/cryptography. qpdf 12.4.2 checks
  the thirteen non-R7 files without warnings. R7 uses the unchanged independent
  pyHanko/cryptography implementations; qpdf does not support AESV4.

To generate and validate a fresh corpus from a checkout:

```console
python -m pip install --require-hashes -r tools/source-mac-validation-requirements.txt
python tools/validate_source_mac.py --php php --qpdf qpdf
```

`--work-dir /explicit/output/directory` retains the generated outputs and manifest.
Cryptographic keys, IVs and serial numbers are fresh; regeneration validates the
same behaviour and is not expected to reproduce the checked-in hashes.
The tool additionally produces twelve malicious variants, refused by both the
independent validator and native reader. Several retain a correctly computed
HMAC while violating source coverage, CMS cardinality or integrity claims.
Unit tests verify the stored corpus and mutate each source before reading.

See the [English MAC contract](../../../docs/source-mac.md) for exact scope,
resource limits and normative references.
