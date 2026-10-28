# CCITT fax decoding

[Français](fr/fax.md)

`StreamDecoder` and `ReadDocument::streamData()` decode `CCITTFaxDecode` to complete, MSB-first, byte-padded monochrome scanlines. The encoded stream and its source bytes remain intact. The implementation is native PHP and uses no image executable or optional package at runtime.

The decoder implements Modified Huffman (1D), mixed 1D/2D and Group 4 (2D), including terminating/make-up codes, pass/horizontal/vertical modes and the T.4/T.6 uncompressed extension. Undefined extension selectors fail explicitly. Positive `K` values all select the same mixed decoder; the decoder does not enforce a different cadence for different positive values. Mixed coding starts with a 1D row.

## Parameters and termination

All PDF table 11 parameters are read with their specified defaults and types: `K`, `Columns`, `Rows`, `EndOfLine`, `EncodedByteAlign`, `EndOfBlock`, `BlackIs1` and `DamagedRowsBeforeError`. Indirect parameter values use the document's explicit object resolver. Width must be positive; row count and permitted damage count must be nonnegative.

EOL patterns are accepted even when `EndOfLine` is false, and required when it is true. Byte alignment checks the inserted zero padding. `EndOfBlock=true` requires the appropriate RTC/EOFB and overrides `Rows`. With `EndOfBlock=false`, decoding stops at `Rows` when positive, or when the available encoded data is exhausted; `Rows=0` is valid. Complete output rows are padded with zero bits that are outside the declared `Columns` samples. `BlackIs1` determines sample polarity. EOD consumes the final partial byte; zero block padding after EOFB is accepted, and subsequent non-padding data is rejected.

Uncompressed T.6 patterns may cross scanline boundaries. `CcittFaxUncompressed` retains at most five pending pixels and the explicit exit colour; it does not allocate an image-wide pixel array. T.4 exits uncompressed mode before line synchronization. The exit codes with zero through four white pixels and either next-run colour are covered separately.

## Damage recovery and budgets

Recovery is enabled only by a positive `DamagedRowsBeforeError`, with `EndOfLine=true` and nonnegative `K`. A malformed row triggers a bounded search for the next EOL. The replacement is the previous healthy row, or a white row when the preceding row was also damaged; the first damaged row is white. The count is cumulative within the stream. Resource-limit failures and undefined extensions never become recoverable row errors. No other implicit resynchronization or correction occurs.

The shared `ReadBudget` charges decoded bytes and retained row/transition data cumulatively. `ReaderLimits` bounds encoded input and decoded stream size before allocation. `CcittFaxLimits` additionally bounds bit reads (lookahead and recovery included), rows, transitions per row and recovery search length. Its defaults are 134,217,728 bit reads, 1,000,000 rows, 1,000,000 transitions per row and 1,048,576 recovery bits. A direct `CcittFaxDecoder` call can use custom codec work limits alongside the same document budget:

```php
$decoder = new CcittFaxDecoder(
    $readerLimits,
    new CcittFaxParameters(k: -1, columns: 1728),
    new CcittFaxLimits(maxRows: 10000),
);
$pixels = $decoder->decode($encoded, $documentBudget);
```

The classes are in `PDF\Reading\Filters`; `$documentBudget` is a `PDF\Reading\ReadBudget`. Each invocation owns its mutable bit/row state; immutable configuration is reusable.

## Frame a prefix without guessing PDF delimiters

`CcittFaxDecoder::decodePrefix(ByteCursor, ReadBudget)` uses the same T.4/T.6 automaton as `decode()`. It consumes exactly one self-delimiting encoded prefix and its zero padding to the next byte. The immutable `CcittFaxPrefix` result exposes `samples`, `encodedBytes`, `rows`, `bitReads` and `transitions`; the supplied cursor remains at the first following byte. Its existing offset is supported without copying the remaining content. Lookahead restores the cursor while retaining its work charge.

A prefix requires `EndOfBlock=true` or a positive `Rows` count. Without either, only a separately bounded whole stream can establish exhaustion; the prefix API refuses to guess a boundary. Reader never searches for `EI` or interprets PDF content syntax. The caller validates its own following delimiter. Zero bytes after the first byte-aligned endpoint remain with the caller; `decode()` still checks the complete input and accepts only permitted zero padding after termination, including when `Rows` supplies the endpoint.

Each call owns its mutable state. The decoded bytes, transition storage and lookahead remain subject to the same byte/work limits. The result's work counters allow a containing content parser to debit its own cumulative document budget as well. The JSON oracle checks samples and exact offsets for all 39 self-delimiting corpus cases; the remaining `Rows=0, EndOfBlock=false` case remains supported only as a complete stream. Regressions additionally exercise adjacent `EI`, literal `EI` bytes inside uncompressed pixels, malformed padding and bounded lookahead.

## Evidence and its limits

`tools/build_fax_fixtures.py` records 40 small synthetic PDF image streams: 14 independently encoded libtiff cases (MH, G3, G4, byte alignment, narrow/non-byte widths, long make-up runs, optional EOL, termination and positive K equivalence) and 26 explicit ITU table vectors for uncompressed mode. The original TIFFs, encoded hashes and expected packed bitmaps are retained in `tests/Fixtures/fax`. The generator imports no PHP decoder.

`tools/validate_fax.py --php php --report _build/fax/report.json` checks reproducibility, the actual Reader PDF→image→decoded-bytes path, pypdf's encoded stream binding, qpdf structure, Pillow/libtiff source pixels and Poppler's decoded pixels for the 14 compressed cases. It also renders a PDF containing the decoded output for every case. The latter checks the output's sample representation; **it is not an independent decoder oracle for the uncompressed extension**. For that extension, evidence consists of explicit normative bitmaps, exhaustive exit-code vectors, transition tests and a separate source review. Poppler/Ghostscript and the other external codecs investigated do not provide a dependable oracle for all of these extension cases. The JSON report distinguishes these evidence categories.

The PHP regression tests additionally cover polarity/padding, malformed codes and parameters, missing markers, cumulative budgets, bounded damaged-row recovery and explicit failures for undefined extensions. The Python tools need the development validation requirements, qpdf and Poppler. Regenerate the libtiff samples only intentionally with `--refresh`; ordinary fixture reproducibility uses `--check`.

## Normative sources

- ISO 32000-2:2020, §7.4.6 and table 11, physical pages 58–59 of the retained EC3 reference (printed pages 43–44).
- [ITU-T T.6 (11/1988)](https://www.itu.int/rec/dologin_pub.asp?id=T-REC-T.6-198811-I%21%21PDF-E&lang=e&type=items), tables 1–4 and §§2.2–2.4. Uncompressed concatenation is on physical page 10, table 4 and EOFB/padding on physical page 11 (printed pages 8–9).
- [ITU-T T.4 (04/1999)](https://www.itu.int/rec/dologin_pub.asp?id=T-REC-T.4-199904-S%21%21PDF-E&lang=e&type=items), tables 4–5 and §§4.2.1–4.2.4, physical pages 18–19 (printed pages 10–11).
