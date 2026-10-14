# External page tree

English | [Français](README.fr.md)

These two PDF 1.7 files are original project content under the MIT licence.
They are produced with **pypdf 6.20.1**, then rewritten with **qpdf 12.4.2**,
without using PDFToolkit's PHP writer. PHP tests can read the committed files
without these tools.

The tree contains six nodes and three pages in this order:

```text
Root Pages (Count 3)
├── Branch Pages (Count 2)
│   ├── Page 1
│   └── Sub-branch Pages (Count 1)
│       └── Page 2
└── Page 3
```

| Page | Effective MediaBox | Effective CropBox | Effective Rotate | Effective Resources |
| --- | --- | --- | --- | --- |
| 1 | `[0 0 300 500]`, branch | `[20 30 280 470]`, branch | `-90`, branch | `FBranch`: Courier, branch |
| 2 | `[0 0 320 520]`, page | `[30 40 270 460]`, sub-branch | `0`, page | Empty dictionary, page |
| 3 | `[0 0 400 600]`, root | `[10 20 390 580]`, root | `90`, root | `FRoot`: Helvetica, root |

The first page checks that the closest ancestor overrides root values.
The second checks multilevel inheritance and its replacement by `Rotate 0`
and an empty `Resources` dictionary. The third checks that the preceding
branch's values do not contaminate its sibling. The branch's `MediaBox`
rectangle is indirect.

Page 1 contains a direct array of two text streams, one raw and one Flate.
Page 2 uses an indirect array of two streams: a greyscale inline image, then
compressed graphics operators. The 297 pixel bytes include every value from
`0x00` to `0xff`, as well as `endstream`, `endobj`, `xref`, `startxref` and
`%%EOF`. Declared stream lengths must therefore be followed. Page 3 omits
`Contents`.

`classic.pdf` uses traditional xref. `object-streams.pdf` represents the same
tree with an xref stream and page dictionaries in an object stream. qpdf may
change object numbers and compress streams; tests follow each file's own
identifiers in `manifest.json`. The manifest records SHA-256 hashes, node
identities, page-local entries, expected inherited values, encodings and all
raw and decoded content bytes in order.

To reproduce and verify from the repository root:

```console
python tools/build_page_tree_fixture.py --qpdf qpdf
python tools/build_page_tree_fixture.py --qpdf qpdf --check
python tools/validate_page_tree_fixture.py --qpdf qpdf
```

The generator fixes file identifiers and inserts no date. With the stated
versions, `--check` compares every PDF and the manifest byte for byte in a
temporary directory. The validator compares references, structure and data
with explicit expectations, checks every file using `qpdf --check`, then
confirms inheritance and text extraction using `PdfReader(strict=True)`.
It also checks with qpdf that all three leaves of the rewritten variant
actually reside in an object stream.
