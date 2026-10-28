# Synthetic CCITT corpus

[Français](README.fr.md)

These MIT-licensed test patterns contain no external document or personal data. `tools/build_fax_fixtures.py` creates the PDFs with pypdf. Its `--refresh` mode intentionally rebuilds the independently compressed TIFF strips with Pillow/libtiff; `--check` verifies the PDF bytes against the retained manifest without regenerating compression.

`manifest.json` contains the encoder version, exact encoded stream hashes and explicit expected bitmaps. Fourteen cases use libtiff. Twenty-six uncompressed-extension cases derive directly from the ITU T.4/T.6 tables and have explicit expected pixels independent of the PHP implementation. They include all exit lengths/colours and patterns crossing T.6 row boundaries. See [the evidence and limitations](../../../docs/fax.md); rendering a decoded PDF is not an independent oracle for decoding the extension.
