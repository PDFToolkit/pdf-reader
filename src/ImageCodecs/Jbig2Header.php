<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** Embedded PDF segment framing and page size; region decoding stays in the backend. @internal */
final class Jbig2Header
{
    private int $segments = 0;
    private InspectionBudget $inspection;
    private ?int $width = null;
    private ?int $height = null;
    private bool $endedPage = false;
    /** @var array<int, true> */
    private array $numbers = [];

    public function read(CodecRequest $request): NativeDescription
    {
        $this->inspection = new InspectionBudget($request);
        if ($request->globals !== null) {
            $this->segments($request->globals, true, $request);
        }
        $this->segments($request->bytes, false, $request);
        if ($this->width === null || $this->height === null) {
            throw new ParseException('Embedded JBIG2 has no page information.');
        }
        // Before 10.03.0, Ghostscript's public filter did not complete a page
        // when the EOF notification arrived after the final input bytes.
        return new NativeDescription(
            $this->width,
            $this->height,
            1,
            1,
            new ColorInformation('Gray'),
            $request,
            inspection: $this->inspection,
            minimumGhostscriptRevision: $this->endedPage ? 0 : 10030
        );
    }

    private function segments(string $bytes, bool $globals, CodecRequest $request): void
    {
        if (str_starts_with($bytes, "\x97JB2\r\n\x1a\n")) {
            throw new ParseException('PDF JBIG2 streams must use embedded segment organization.');
        }
        $cursor = new HeaderCursor($bytes, $this->inspection);
        while ($cursor->position < $cursor->length) {
            if (++$this->segments > $request->limits->maxSegments) {
                throw new CodecLimit('JBIG2 segments exceed their cumulative limit.');
            }
            $number = $cursor->uint32();
            if (isset($this->numbers[$number])) {
                throw new ParseException('Duplicate JBIG2 segment number.');
            }
            $flags = $cursor->byte();
            $type = $flags & 63;
            if ($this->endedPage && $type !== 51) {
                throw new ParseException('JBIG2 page data follows an end-of-page segment.');
            }
            $countFlags = $cursor->byte();
            $count = $countFlags >> 5;
            if ($count === 7) {
                $count = (($countFlags & 31) << 24) | ($cursor->byte() << 16) | $cursor->word();
                if ($count > $request->limits->maxSegments) {
                    throw new CodecLimit('JBIG2 segment references exceed their limit.');
                }
                $cursor->read(intdiv($count + 8, 8));
            } elseif ($count > 4 || (($countFlags & 31) >> ($count + 1)) !== 0) {
                throw new ParseException('Invalid JBIG2 reference count or retention flags.');
            }
            for ($i = 0; $i < $count; ++$i) {
                $reference = $number <= 256 ? $cursor->byte() : ($number <= 65536 ? $cursor->word() : $cursor->uint32());
                if ($reference >= $number || !isset($this->numbers[$reference])) {
                    throw new ParseException('JBIG2 refers to an unavailable preceding segment.');
                }
            }
            $page = ($flags & 64) !== 0 ? $cursor->uint32() : $cursor->byte();
            if ($globals && $page !== 0) {
                throw new ParseException('PDF JBIG2 global/page segment association is invalid.');
            }
            if (!$globals && $page > 1) {
                throw new CodecUnavailable('Non-normalized JBIG2 page associations require the PHP decoder.');
            }
            $length = $cursor->uint32();
            if ($length === 0xffffffff) {
                throw new CodecUnavailable('Unknown-length JBIG2 regions require the PHP segment decoder.');
            }
            $body = $cursor->read($length);
            if (($type === 49 && ($globals || $page !== 1 || $length !== 0 || $this->width === null))
                || ($type === 51 && ($globals || $page !== 0 || $length !== 0 || $cursor->position !== $cursor->length))) {
                throw new ParseException('Invalid JBIG2 terminal segment.');
            }
            if ($type === 49) {
                $this->endedPage = true;
            }
            $this->capability($type, $body);
            // Integer-key hash bucket, zval, growth capacity and lookup work.
            $this->inspection->retain(128);
            $this->inspection->work(1);
            $this->numbers[$number] = true;
            if ($type === 48) {
                if ($globals || $page !== 1 || $this->width !== null || $length !== 19) {
                    throw new ParseException('Invalid or repeated JBIG2 page information.');
                }
                $information = new HeaderCursor($body, $this->inspection);
                $this->width = $information->uint32();
                $this->height = $information->uint32();
                if ($this->height === 0xffffffff) {
                    throw new CodecUnavailable('Striped JBIG2 pages require the PHP plane decoder.');
                }
            }
        }
    }

    private function capability(int $type, string $body): void
    {
        if (in_array($type, [4, 20, 36, 40, 62], true)) {
            throw new CodecUnavailable('JBIG2 intermediate regions and extension segments require the PHP decoder.');
        }
        $data = new HeaderCursor($body, $this->inspection);
        if ($type === 0) {
            $flags = $data->word();
            if (($flags & 0x300) !== 0 || ($flags & 3) === 3) {
                throw new CodecUnavailable('JBIG2 retained contexts and Huffman refinement require the PHP decoder.');
            }
        } elseif (in_array($type, [6, 7, 22, 23, 38, 39], true)) {
            $data->read(17);
            $flags = in_array($type, [6, 7], true) ? $data->word() : $data->byte();
            if ((in_array($type, [6, 7], true) && ($flags & 3) === 3)
                || (in_array($type, [22, 23], true) && ($flags & 0x80) !== 0)
                || (in_array($type, [38, 39], true) && ($flags & 0x10) !== 0)) {
                throw new CodecUnavailable('This JBIG2 region uses a mechanism whose native samples are not qualified; use the PHP decoder.');
            }
        }
    }
}
