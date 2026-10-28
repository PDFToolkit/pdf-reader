<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** Native qualification is deliberately narrower than the lossless PHP plane API. @internal */
final class JpxHeader
{
    private ?string $codestream = null;
    private ?int $colour = null;
    private int $boxes = 0;
    private int $metadata = 0;
    private InspectionBudget $inspection;

    public function read(CodecRequest $request): NativeDescription
    {
        $this->inspection = new InspectionBudget($request);
        if (str_starts_with($request->bytes, "\xff\x4f")) {
            $this->codestream = $request->bytes;
        } else {
            if (!str_starts_with($request->bytes, "\0\0\0\x0cjP  \r\n\x87\n")) {
                throw new ParseException('JPX data needs a JPEG 2000 codestream or JP2 signature.');
            }
            $this->boxes(new HeaderCursor($request->bytes, $this->inspection), $request, 0);
        }
        if ($this->codestream === null) {
            throw new ParseException('JP2 has no codestream.');
        }
        $cursor = new HeaderCursor($this->codestream, $this->inspection);
        if ($cursor->word() !== 0xff4f || $cursor->word() !== 0xff51) {
            throw new ParseException('JPEG 2000 SIZ must follow SOC.');
        }
        $length = $cursor->word();
        $profile = $cursor->word();
        $x = $cursor->uint32();
        $y = $cursor->uint32();
        $originX = $cursor->uint32();
        $originY = $cursor->uint32();
        $tileWidth = $cursor->uint32();
        $tileHeight = $cursor->uint32();
        $tileX = $cursor->uint32();
        $tileY = $cursor->uint32();
        $components = $cursor->word();
        if ($length !== 38 + 3 * $components || $x <= $originX || $y <= $originY || $tileWidth === 0 || $tileHeight === 0
            || $tileX > $originX || $tileY > $originY || $components < 1) {
            throw new ParseException('Invalid JPEG 2000 SIZ geometry.');
        }
        if ($components > $request->limits->maxComponents) {
            throw new CodecLimit('JPEG 2000 component count exceeds its limit.');
        }
        $columns = intdiv($x - $tileX + $tileWidth - 1, $tileWidth);
        $rows = intdiv($y - $tileY + $tileHeight - 1, $tileHeight);
        if ($columns > intdiv($request->limits->maxTiles, $rows)) {
            throw new CodecLimit('JPEG 2000 tiles exceed their count limit.');
        }
        if ($originX !== 0 || $originY !== 0 || $profile > 2 || !in_array($components, [1, 3, 4], true)) {
            throw new CodecUnavailable('This JPEG 2000 origin, profile or component layout requires the PHP plane decoder.');
        }
        $precision = null;
        for ($i = 0; $i < $components; ++$i) {
            $sample = $cursor->byte();
            $horizontal = $cursor->byte();
            $vertical = $cursor->byte();
            $bits = ($sample & 127) + 1;
            if ($bits > 38 || $horizontal === 0 || $vertical === 0) {
                throw new ParseException('Invalid JPEG 2000 component sampling.');
            }
            if (($sample & 128) !== 0 || !in_array($bits, [1, 2, 4, 8, 16], true) || $horizontal !== 1 || $vertical !== 1
                || ($precision !== null && $precision !== $bits)) {
                throw new CodecUnavailable('Native JPX would alter this precision, signedness or component grid; use the PHP decoder.');
            }
            $precision = $bits;
        }
        $expected = match ($this->colour) {
            17 => 1, 16 => 3, 12 => 4, default => $components
        };
        if ($expected !== $components) {
            throw new ParseException('JP2 colour specification disagrees with its components.');
        }
        $space = match ($this->colour) {
            17 => 'Gray', 16 => 'RGB', 12 => 'CMYK', default => 'Unknown'
        };
        return new NativeDescription($x, $y, $components, $precision, new ColorInformation($space, $this->colour, specifications: $this->colour === null ? [] : [new ColorSpecification(1, 0, 0, pack('N', $this->colour), $this->colour)], selectedSpecification: $this->colour === null ? null : 0), $request, inspection: $this->inspection);
    }

    private function boxes(HeaderCursor $cursor, CodecRequest $request, int $depth): void
    {
        if ($depth >= $request->maximumDepth) {
            throw new CodecLimit('JP2 box nesting exceeds its depth limit.');
        }
        while ($cursor->position < $cursor->length) {
            if (++$this->boxes > $request->limits->maxSegments) {
                throw new CodecLimit('JP2 box count exceeds its limit.');
            }
            $start = $cursor->position;
            $length = $cursor->uint32();
            $type = $cursor->read(4);
            if ($length === 1) {
                $high = $cursor->uint32();
                $low = $cursor->uint32();
                if ($high !== 0) {
                    throw new CodecLimit('JP2 extended box length exceeds the bounded input.');
                }
                $length = $low;
            } elseif ($length === 0) {
                $length = $cursor->length - $start;
            }
            $bodyLength = $length - ($cursor->position - $start);
            if ($bodyLength < 0) {
                throw new ParseException('Invalid JP2 box length.');
            }
            $body = $cursor->read($bodyLength);
            if ($type === 'jp2c') {
                if ($this->codestream !== null) {
                    throw new CodecUnavailable('Multiple JP2 codestreams need an explicit image composition implementation.');
                }
                $this->codestream = $body;
            } else {
                $this->metadata += $bodyLength;
                if ($this->metadata > $request->limits->maxMetadataBytes) {
                    throw new CodecLimit('JP2 metadata exceeds its byte budget.');
                }
                if ($type === 'ftyp') {
                    if ($bodyLength < 12 || ($bodyLength - 8) % 4 !== 0) {
                        throw new ParseException('Invalid JP2 file-type box.');
                    }
                    if (substr($body, 0, 4) !== 'jp2 ') {
                        throw new CodecUnavailable('JPX and other file brands require the PHP decoder.');
                    }
                    for ($i = 8; $i < $bodyLength; $i += 4) {
                        if (substr($body, $i, 4) !== 'jp2 ') {
                            throw new CodecUnavailable('Extended JP2 compatibility declarations require the PHP decoder.');
                        }
                    }
                } elseif (in_array($type, ['jpch', 'jplh', 'cgrp', 'opct', 'rreq', 'ftbl', 'dtbl', 'jpxb', 'comp', 'asoc', 'nlst'], true)) {
                    throw new CodecUnavailable('JPX reader requirements, fragments, layers or extended metadata require the PHP decoder.');
                } elseif ($type === 'jp2h') {
                    if ($depth !== 0) {
                        throw new ParseException('JP2 image headers cannot contain nested image-header boxes.');
                    }
                    $this->boxes(new HeaderCursor($body, $this->inspection), $request, $depth + 1);
                } elseif (in_array($type, ['pclr', 'cmap', 'cdef'], true)) {
                    throw new CodecUnavailable('JP2 palette and channel mappings require the PHP plane decoder.');
                } elseif ($type === 'colr') {
                    if ($this->colour !== null || $bodyLength !== 7 || ord($body[0]) !== 1 || ord($body[1]) !== 0 || ord($body[2]) !== 0) {
                        throw new CodecUnavailable('JP2 colour profiles or precedence require the PHP metadata decoder.');
                    }
                    $this->inspection->retain(256);
                    $color = new HeaderCursor(substr($body, 3), $this->inspection);
                    $this->colour = $color->uint32();
                    if (!in_array($this->colour, [12, 16, 17], true)) {
                        throw new CodecUnavailable('Native JPX colour conversion would alter the encoded component interpretation.');
                    }
                }
            }
        }
    }
}
