# Read pages and their inheritance

English | [Français](fr/pages.md)

A PDF catalog identifies a tree root through `/Pages`. Its branches are
`/Pages` dictionaries; its leaves are `/Page` dictionaries. The `/Kids` array
gives descendant order. `/Count` declares a branch's leaf count, while `/Parent`
connects a descendant to its parent. Reading only the root's `/Count` therefore
does not validate pages. These rules come from ISO 32000-1, 7.7.3 and tables
29 and 30.

## Explicitly requested traversal

After obtaining a `ReadDocument` with `DocumentReader::read()`, construct the
tree reader with this document and traversal ceilings:

```php
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$tree = (new PageTreeReader($document, new PageTreeLimits(
    maxDepth: 64,
    maxNodes: 10_000,
    maxPages: 5_000,
)))->read();

foreach ($tree as $index => $page) {
    printf(
        "Page %d; effective rotation: %d\n",
        $index + 1,
        $page->attributes->rotate->integer(),
    );
}
```

`read()` follows children in `/Kids` order and returns its collection only after
checking the entire tree. Each branch must declare the number of leaves
actually encountered. The root must not have a `/Parent`; other branches and
pages must identify their immediate parent. Cycles, repeated references and
inconsistent structural values raise a `ParseException`.

`ReadPageTree` implements `Countable` and `IteratorAggregate`. `count($tree)`
gives the validated page count; `$tree->page(0)` gives the first page.
The collection exposes its root reference, document and pages. Each `ReadPage`
retains a source reference, resolved dictionary and their owning document.
Calculated `PageAttributes` are PDF objects: a resource `DictionaryType`, two
`Rectangle` values and an `IntegerType`. Traversal does not transform these
values into application associative arrays.

## A page's effective values

Four entries can be inherited: `/Resources`, `/MediaBox`, `/CropBox` and
`/Rotate`. A value present on the page takes precedence; otherwise the closest
ancestor defining it is used. A null entry is treated as absent, as required by
PDF dictionary rules. `/Resources` and `/MediaBox` are required after this
search. `/CropBox` defaults to `/MediaBox`; `/Rotate` defaults to zero and must
be a multiple of 90 degrees.

Inheritance selects a complete value. A local `/Resources` dictionary does
not merge its categories with the parent's: even when empty, it replaces
inherited resources. The source dictionary remains accessible to distinguish
actually written entries from calculated effective values.

`PageAttributes::rotate` retains the original PDF integer. Its
`normalizedRotation()` method gives an orientation of 0, 90, 180 or 270 degrees:
a rotation of `-90` remains an `IntegerType(-90)` and corresponds to 270 degrees.
Rectangle coordinates are preserved as well. The reader does not calculate
a drawing transformation or screen position.

Other entries remain local to the page. In particular, `/Contents` is not
inherited, nor are `/BleedBox`, `/TrimBox`, `/ArtBox` or `/UserUnit`.
Effective values for those last entries are not calculated by this traversal;
the application can inspect their definition and source value through the
shared model's objects.

## Separate content streams

A page may omit `/Contents`, in which case it has no content streams. When
present, this object identifies a single stream or an array of streams.
Array order is part of the document. Splitting between two streams does not
necessarily delimit independent drawing operations: under ISO 32000-1, 7.8.2,
their effect corresponds to concatenation in that order.

Preserving stream objects and their order allows decoding to be requested
later. Decoding produces bytes without interpreting operators, resolving fonts
or extracting visible text. Unknown compressed data can remain accessible in
encoded form.

```php
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;

$contentsReader = new PageContentsReader($document, new PageContentLimits(
    maxStreams: 128,
));

foreach ($tree as $pageIndex => $page) {
    $contents = $contentsReader->read($page);
    printf("Page %d; streams: %d\n", $pageIndex + 1, count($contents));

    foreach ($contents as $streamIndex => $stream) {
        $encodedBytes = $stream->encodedBytes();
        $decodedBytes = $document->streamData($stream);
        printf(
            "Stream %d; encoded: %d bytes; decoded: %d bytes\n",
            $streamIndex + 1,
            strlen($encodedBytes),
            strlen($decodedBytes),
        );
    }
}
```

`PageContents` retains its owning page and a list of `EncodedStream` values.
`read()` rejects a page belonging to another `ReadDocument`. An absent or null
entry and an empty array yield an empty collection. A nonempty entry must
resolve to a stream or an array in which each element resolves to a stream;
no invalid element is ignored.

## Try the independent fixtures

The [executable example](../examples/pages.php) uses the three-page tree created
with pypdf and qpdf. From a reader checkout:

```console
composer install
php examples/pages.php
php examples/pages.php tests/Fixtures/page-tree/object-streams.pdf
```

The first page inherits from a branch: its raw rotation is `-90` and it has two
streams. The second defines its own empty resources and zero rotation; its
CropBox comes from the sub-branch and its two streams are referenced by an
indirect array. The third uses root attributes, with rotation 90, and has no
streams. The qpdf variant represents the same pages in an object stream with
different numbers. The [fixture description](../tests/Fixtures/page-tree/README.md)
lists the effective values and their origin.

## Limit each operation's work

| Limit | Default | Scope |
| --- | --- | --- |
| `PageTreeLimits::maxDepth` | 64 | Tree depth, including the root. |
| `PageTreeLimits::maxNodes` | 100,000 | Cumulative count of visited branches and leaves. |
| `PageTreeLimits::maxPages` | 100,000 | Leaf count and ceiling for declared counts. |
| `PageContentLimits::maxStreams` | 1,024 | Number of streams in a page's contents. |

These ceilings apply to one operation. `ReaderLimits` and cumulative document
budgets continue to control loaded objects, parsed values, retained bytes and
decoding. A tree may be deep while its dictionaries have little syntactic
nesting: tree depth therefore needs its own ceiling. Traversal uses an explicit
stack; it does not recursively use the PHP call stack for every descendant.

## Scope of checks

Page traversal validates the structure it visits. It does not render the
document or validate all annotations, resources, fonts or actions its pages
may reference. These references remain source document data; they do not
become imported writer objects.

[Architecture](architecture.md) · [Components](components.md) ·
[Security controls](security.md) · [README](../README.md)
