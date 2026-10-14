# External test PDFs

English | [Français](README.fr.md)

These five small documents are created independently of this repository's PHP
writer. Text, dictionaries and binary data are original project content,
distributed under its MIT licence. No third-party PDF is reused.

`tools/build_reader_fixtures.py` creates the initial document with **pypdf 6.20.1**,
then **qpdf 12.4.2** produces a representation with object streams and an xref
stream. Other variants add traditional revisions: offsets, xref entries and
`/Prev` links are calculated directly in Python; object and trailer values
are serialised by pypdf.

Versions are pinned, file identifiers are deterministic and no variable date
is added. PHP tests read committed binaries and do not require Python, pypdf
or qpdf.

| File | Exercised case |
| --- | --- |
| `classic.pdf` | PDF 1.7, traditional xref, one Helvetica page; octal and hexadecimal strings, escaped name, nested array and dictionary; 297-byte stream whose `/Length` is reference `7 0 R`. |
| `object-streams.pdf` | Same document rewritten by qpdf: catalog in object stream `1 0` (`/N 6`, `/First 34`); xref stream `10 0` with `/W [1 2 1]`, Flate and PNG predictor 12 (`/Columns 4`). |
| `incremental.pdf` | Two revisions; Info object `1 0` is replaced and its title becomes `Updated revision`; `/Prev` points to the old xref. All `classic.pdf` bytes are preserved as a prefix. |
| `deleted-object.pdf` | Two revisions; object `9 0` becomes free, with next generation 1 and an updated free list. The catalog's `9 0 R` reference must resolve to null. |
| `reused-generation.pdf` | Three revisions; the deleted number is reused as `9 1`, containing `reused generation one`. `9 0 R` stays null; `9 1 R` resolves to the new value. |

Every page contains `Independent reader fixture` in a 58-byte content stream.
The catalog also contains all 256 byte values in `/FixtureBinary`, the string
`A (nested) \ line` followed by a newline, a tab and `end` in `/FixtureLiteral`,
and the name `/A name/#()` in `/FixtureName`. `/FixtureData` identifies an
independent binary stream: its bytes include `endstream`, `endobj`, `xref`,
`startxref` and `%%EOF`. Only its actual length allows it to be read correctly.

`manifest.json` records useful identifiers, lengths, SHA-256 hashes and final
xref offsets. Numbers change in the qpdf variant; a reader must follow
references rather than assume the initial document's numbers.

## Reproduce and check

In a Python development environment, install `pypdf==6.20.1` and make qpdf 12.4.2
available, then run from the repository root:

```console
python tools/build_reader_fixtures.py --qpdf qpdf
python tools/build_reader_fixtures.py --qpdf qpdf --check
```

`--qpdf` also accepts the full executable path. `--check` recreates files in a
temporary directory and compares their bytes with the fixtures without changing
them. The script validates every file using `qpdf --check`, then reads its page,
text and binary values using `PdfReader(strict=True)`. It also checks revision
prefixes and metadata replacement. qpdf explicitly confirms null values for
deleted objects and outdated-generation references, and the value of `9 1`
after reuse.

pypdf 6.20.1 retains an old in-use entry when resolving the initial generation
of a subsequently deleted object, so it is not used as the oracle for that
case. Expected effective state follows ISO 32000-1, 7.3.9 and 7.5.4–7.5.6 and
is confirmed with qpdf. PHP reader tests must exercise this distinction.
