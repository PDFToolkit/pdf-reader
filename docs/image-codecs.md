# Image decoding: native filters and PHP planes

English | [Français](fr/image-codecs.md)

Reader 0.7 decodes `DCTDecode`, `JPXDecode` and `JBIG2Decode` on an explicit image or stream decoding request. Opening a PDF, resolving its catalog and reading its encoded streams do not launch an image decoder. Xref and object-stream parsing cannot activate one. [CCITT fax](fax.md) remains a separate built-in PHP filter.

## Installation and selection

The default `ImageCodecs\AutomaticDecoder` tries the bounded native adapter first. On Linux, this requires application-installed `gs` (Ghostscript), `prlimit` (util-linux) and PHP `proc_open`. Executable discovery uses absolute directories from the application's `PATH`; `NativeTools` also accepts explicit absolute executable paths. The Reader does not install programs or download codecs.

When native tools are absent, a compiled filter is unavailable, or its sample/metadata profile cannot be preserved, the adapter uses the optional pure PHP package:

```console
composer require "pdftoolkit/pdf-codecs:^0.1"
```

If it is missing, `CodecUnavailable` contains this command. Malformed data and exceeded limits never trigger a retry through another engine. The PHP library can itself report an unsupported feature; installing it does not promise recovery of arbitrary damaged files.

Applications can choose a backend explicitly:

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\ImageCodecs\AutomaticDecoder;
use PDF\Reading\ImageCodecs\GhostscriptDecoder;
use PDF\Reading\ImageCodecs\ImageLimits;
use PDF\Reading\ImageCodecs\NativeTools;
use PDF\Reading\ImageCodecs\PhpDecoder;

$phpOnly = new DocumentReader(imageDecoder: new PhpDecoder());
$native = new GhostscriptDecoder(new NativeTools('/usr/bin/gs', '/usr/bin/prlimit'));
$automatic = new DocumentReader(
    imageDecoder: new AutomaticDecoder($native, new PhpDecoder()),
    imageLimits: new ImageLimits(maxPixels: 4_000_000, nativeWallMilliseconds: 5000),
);
```

`Decoder::decode(CodecRequest): DecodedImage` also allows an application adapter. These Reader contracts have no dependency on optional package classes. Ghostscript is installed separately and retains its own [licensing terms](https://www.ghostscript.com/licensing/); it is not redistributed with the Reader.

## Exact component data

Given a resolved `EncodedStream` belonging to a `ReadDocument`, `$document->imageData($stream)` returns the decoded image. Image and byte requests share one document cache.

| Value | Meaning |
| --- | --- |
| `DecodedImage::width`, `height`, `originX`, `originY` | Intrinsic image extent and reference-grid origin. |
| `components` | Ordered `ComponentPlane` objects. Each exposes its dimensions, precision 1–38, signedness, grid separation/origin, `rowBytes` and `bytes`. |
| `ComponentPlane::sample($x, $y)` | Integer sample, including sign extension for a signed component. |
| `ComponentPlane::alpha` | `none`, `unassociated` or `associated`; colour and alpha planes remain separate. |
| `color` | Colour space designation, enumerated value, exact ICC bytes and parameters; all colour specifications retain method, precedence, approximation and raw method data, with the selected index. |
| `channelDefinitions` | Original channel/type/association and corresponding output plane. |
| `chromaKey` | Preserved JPX `opct` type 2 values. PDF ignores this JPX chroma-key opacity mechanism; the Reader does not turn it into alpha. |
| `backend` | `ghostscript`, `php`, or the application adapter's designation. |

Plane bytes are packed most-significant-bit first, with each row padded to a byte boundary. They are not PNG files. `streamData()` interleaves only uniform, unsigned, full-resolution planes with zero origins and no alpha. A distinct grid, signed sample or alpha channel raises `CodecUnavailable` directing the caller to `imageData()` instead of silently reducing the representation. Uniform unsigned precision is preserved even when it is not eight bits.

The caller still applies the PDF image dictionary: colour-space precedence, `Decode`, masks, `SMaskInData`, soft-mask composition and display transforms. Preserved ICC bytes are not a colour-management engine. No codec grants document permissions or signature authority.

## Filter semantics and native profiles

### DCT / JPEG

Eight-bit sequential and progressive Huffman JPEG includes subsampling, restart intervals, separate/interleaved scans and Adobe colour transforms. APP14 takes precedence over `ColorTransform`; without it, the explicit parameter wins, then PDF defaults to transformation for three components and no transformation otherwise. RGB component identifiers and JFIF heuristics do not replace this PDF default. YCbCr yields RGB; YCCK yields the PDF CMYK components. Four-component data is not automatically inverted or converted to display RGB. ICC chunks are preserved.

IDCT rounding and chroma upsampling can differ between engines. The PHP implementation uses nearest-neighbour component expansion; the native JPEG library can interpolate chroma. The independent tests compare full-resolution samples with declared rounding tolerances and do not claim bit identity between all subsampled JPEG renderings. Applications requiring one reproducible expansion policy can select `PhpDecoder` explicitly. Other JPEG processes and sample precisions fail explicitly when no installed backend supports the PDF profile.

### JPX / JPEG 2000

The native adapter accepts only qualified original planes: one, three or four unsigned components with equal 1-, 2-, 4-, 8- or 16-bit precision, unit separation, zero origin and a supported codestream profile. A simple Gray/RGB/CMYK colour declaration is retained. Palettes, channel mapping, alpha, ICC or alternative colour specifications, layer/opacity metadata, other precisions, signed samples and distinct grids select PHP before any native process starts. JPX file brands, reader requirements, fragments and composition containers also select PHP before requiring a contiguous codestream. Tile and box limits apply during inspection.

The PHP result preserves the codec library's precision, planes and colour/channel metadata. It does not narrow 12-, 24- or higher-bit samples to eight bits. Unsupported JPEG 2000 extensions remain explicit failures, rather than a rendered approximation. The [codec package](https://github.com/PDFToolkit/pdf-codecs) documents its implemented codestream and metadata mechanisms.

### JBIG2

Samples follow the PDF convention: zero is black, one is white. `JBIG2Globals` is resolved, authenticated/decrypted and decoded through the same document pipeline before the image request. Shared globals use the cache; recursion and cumulative byte budgets cover the complete chain. The native adapter validates embedded segment boundaries and references, then submits page-zero global segments followed by page segments to the native filter. It does not pass the PDF dictionary as executable code.

Known incompatible native mechanisms are detected before execution: intermediate text/halftone/generic/refinement regions; retained bitmap contexts; Huffman refinement dictionaries/text; the extended 12-adaptive-template flag; halftone `HDEFPIXEL=1`; extension segments; unknown segment lengths and striped/unknown-height pages. These use PHP. This conservative selection avoids the confirmed missing or incorrect behaviours in libjbig2dec 0.20. It is not a claim that the native library implements those mechanisms. Non-normalized page associations also use PHP; decoding is not exhaustive PDF conformance validation.

## Bounds and process isolation

`ReaderLimits` continues to bound input, output, nesting, retained data and cumulative decoded bytes. `ImageLimits` adds pixels, dimensions, components, metadata, segments, tiles, work and intermediate memory. Native preflight charges encoded-byte inspection, segment-index/ICC state and plane assembly to a shared budget; the native codec itself is bounded separately by process CPU/time/memory limits. Native output dimensions/byte counts are checked, and metadata retained in the document is charged to its budget. Filtered globals consume the same cumulative decoded-byte allowance as page samples.

Native execution uses an argument array, a fixed filter-only program and binary standard input. No shell, input PDF interpreter or rendering device is involved. The supplied image never becomes PostScript source. Inherited `GS_OPTIONS`, `GS_LIB`, home-directory configuration and other environment settings are removed. `prlimit` bounds address space, CPU, descriptors and file output; the parent also bounds elapsed time, stdout and stderr and terminates a process that exceeds them. This is bounded process execution, not a claim of a complete operating-system sandbox.

Defaults include 16,777,216 pixels, 65,536 per dimension, 16 components, 100,000,000 work units, 256 MiB intermediate PHP allocation, 4 MiB metadata, 100,000 segments and 4,096 tiles. Native defaults are 768 MiB address space, 10 CPU seconds, 15 elapsed seconds and 64 KiB diagnostics. Applications can lower or raise these explicit limits. Resource accounting is conservative; input copies and row padding also count.

| Failure | Result |
| --- | --- |
| Missing tools/package or unsupported sample-preserving profile | `CodecUnavailable`, a subtype of `UnsupportedPdfFeature`. Only this condition selects the next backend. |
| Malformed data or native warning/repair | `ParseException`; no fallback. |
| Output, geometry, memory, work or process limit | `CodecLimit`, a subtype of `ParseException`; no fallback. |

## Reproducing validation

```console
python -m pip install --require-hashes -r tools/validation-requirements.txt
python tools/validate_image_codecs.py --php php --require-native --report _build/image-codecs.json
```

This requires the optional PHP package, Ghostscript/prlimit, qpdf and Poppler's `pdfimages`. `--php`, `--qpdf` and `--pdfimages` accept configured executable paths. Without `--require-native`, the oracle also supports PHP-only environments; the report states that difference.

The durable corpus covers exact OpenJPEG reference planes, declared JBIG2 bitmaps and native profile decisions, live Pillow/libjpeg and Poppler JPEG comparisons, and clear/AES-256 PDF fixtures with Flate-encoded globals checked through pypdf, qpdf and both Reader engines. Tests additionally cover passive parsing, cache sharing, fallback classification, cycles, cumulative budgets, native deadlines and sample metadata. Generated files and provenance are listed in [the fixture directory](../tests/ImageCodecs/Fixtures/README.md). Finite fixtures and independent tools provide evidence for these cases; they do not prove universal conformance.

Normative anchors: ISO 32000-1/2 §7.4.7 (JBIG2), §7.4.8 (DCT), §7.4.9 (JPX) and §8.9 (PDF image interpretation).
