# Independent inspection fixtures

[Français](README.fr.md)

These original fixtures are distributed under the repository's MIT license.
`tools/build_inspection_fixtures.py` writes their physical PDF syntax independently
of PDFToolkit. qpdf **12.4.2** produces the object-stream variant with a static ID;
the manifest records every file's byte count and SHA-256. No external document or
PDFToolkit writer output is used.

| File | Purpose |
| --- | --- |
| `classic.pdf` | Two pages, a structure element with generation 7, an indirect `/K` array, ordered MCID/MCR/OBJR children, original properties and references, inherited and explicit page associations. |
| `object-streams.pdf` | qpdf's independent rewrite of the same document, with compressed objects and renumbered references. |
| `untagged.pdf` | Page and attachment inspection without a structure tree or a successful HTML derivation. |
| `cycles-and-missing.pdf` | An ancestor cycle, a repeated element, an absent object, a free object and a reference using the wrong generation. Intentionally malformed structure. |

The tagged documents contain a root role map and two distinct namespace
dictionaries with the **same URI** but different mappings. Associated files occur
on the catalog, a page and a structure element; a fourth file occurs in a nested
`EmbeddedFiles` name tree. Their stream metadata includes an unknown filter and
an external stream location. The inspection must neither decode nor acquire
those streams. A catalog JavaScript action, a URI action and HTML-like strings
are inert source properties, never executable instructions.

Binary strings, UTF-16BE and UTF-8 strings, escaped PDF names, nulls, booleans,
real numbers and signed integers outside JavaScript's exact-integer range check
that inspection preserves PDF types and bytes. The original parsed values are
compared, not the original lexical spelling of whitespace or numeric tokens.

Reproduce and validate from the repository root:

```sh
python tools/build_inspection_fixtures.py --check
python tools/validate_inspection.py --report _build/inspection-validation.json
```

Use `--qpdf /path/to/qpdf` and `--php /path/to/php` when necessary. Remove
`--check` only when intentionally regenerating fixtures. The oracle parses the
source with the separately pinned pypdf dependency, compares actual JSON from
`DocumentInspector`, verifies qpdf syntax and compressed objects, checks source
SHA-256 before and after inspection, and exercises small cumulative budgets.
Stream bytes are never included in the inspection JSON. pypdf removes `/Length`
from parsed stream dictionaries; the oracle independently checks this property
against each fixture's preserved raw stream length. qpdf's syntax check does not
certify PDF/UA conformance or the semantics of the structure tree.
