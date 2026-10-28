<?php

declare(strict_types=1);

namespace PDF\Reading\ImageCodecs;

use PDF\Reading\ParseException;

/** PDF DCT metadata and framing, independent of an optional JPEG implementation. @internal */
final readonly class JpegHeader
{
    public function read(CodecRequest $request): NativeDescription
    {
        $inspection = new InspectionBudget($request);
        $cursor = new HeaderCursor($request->bytes, $inspection);
        if ($cursor->word() !== 0xffd8) {
            throw new ParseException('DCT data must start with JPEG SOI.');
        }
        $width = $height = $components = 0;
        $adobe = null;
        $icc = [];
        $iccCount = null;
        $metadata = $segments = 0;
        $scanning = false;
        $finished = false;
        while ($cursor->position < $cursor->length) {
            $value = $cursor->byte();
            if ($value !== 255) {
                if ($scanning) {
                    continue;
                }
                throw new ParseException('Invalid JPEG marker boundary.');
            }
            do {
                $marker = $cursor->byte();
            } while ($marker === 255);
            if ($scanning && ($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd7))) {
                if ($marker === 0) {
                    continue;
                }
            } else {
                $scanning = false;
            }
            if (++$segments > $request->limits->maxSegments) {
                throw new CodecLimit('JPEG marker count exceeds its limit.');
            }
            if ($marker >= 0xd0 && $marker <= 0xd7) {
                if (!$scanning) {
                    throw new ParseException('JPEG restart marker lies outside a scan.');
                }
                continue;
            }
            if ($marker === 0xd9) {
                $finished = true;
                break;
            }
            if ($marker === 0xd8 || $marker === 0) {
                throw new ParseException('Unexpected JPEG standalone marker.');
            }
            if ($marker === 0xdc || $marker === 0x01 || $marker === 0xcc || $marker === 0xde || $marker === 0xdf
                || ($marker >= 0xc0 && $marker <= 0xcf && !in_array($marker, [0xc0, 0xc1, 0xc2, 0xc4], true))) {
                throw new CodecUnavailable('This JPEG process requires a different codec implementation.');
            }
            $length = $cursor->word() - 2;
            if ($length < 0) {
                throw new ParseException('Invalid JPEG segment length.');
            }
            $body = $cursor->read($length);
            if (in_array($marker, [0xc0, 0xc1, 0xc2], true)) {
                if ($components !== 0 || $length < 6) {
                    throw new ParseException('Invalid or duplicate JPEG frame.');
                }
                $frame = new HeaderCursor($body, $inspection);
                if ($frame->byte() !== 8) {
                    throw new CodecUnavailable('Native DCT decoding cannot preserve this JPEG sample precision.');
                }
                $height = $frame->word();
                $width = $frame->word();
                $components = $frame->byte();
                if ($components < 1 || $components > 4 || $length !== 6 + $components * 3) {
                    throw new ParseException('Invalid PDF JPEG frame components.');
                }
            }
            if ($marker === 0xda) {
                $scanning = true;
            }
            if (($marker >= 0xe0 && $marker <= 0xef) || $marker === 0xfe) {
                $metadata += $length;
                if ($metadata > $request->limits->maxMetadataBytes) {
                    throw new CodecLimit('JPEG metadata exceeds its byte limit.');
                }
                if ($marker === 0xee && str_starts_with($body, 'Adobe')) {
                    if ($length !== 12 || ord($body[11]) > 2 || ($adobe !== null && $adobe !== ord($body[11]))) {
                        throw new ParseException('Invalid or conflicting JPEG Adobe transform.');
                    }
                    $adobe = ord($body[11]);
                }
                if ($marker === 0xe2 && str_starts_with($body, "ICC_PROFILE\0")) {
                    if ($length < 14) {
                        throw new ParseException('Truncated JPEG ICC metadata.');
                    }
                    $index = ord($body[12]);
                    $count = ord($body[13]);
                    if ($index < 1 || $index > $count || isset($icc[$index]) || ($iccCount !== null && $iccCount !== $count)) {
                        throw new ParseException('Invalid JPEG ICC sequence.');
                    }
                    $inspection->retain(128 + 2 * ($length - 14));
                    $iccCount = $count;
                    $icc[$index] = substr($body, 14);
                }
            }
        }
        if (!$finished || $components === 0 || ($iccCount !== null && count($icc) !== $iccCount)
            || ($adobe === 1 && $components !== 3) || ($adobe === 2 && $components !== 4)) {
            throw new ParseException('Incomplete or inconsistent JPEG image.');
        }
        ksort($icc);
        $transform = $adobe === null ? ($request->colorTransform ?? ($components === 3 ? 1 : 0)) : ($adobe === 0 ? 0 : 1);
        $space = match (true) {
            $components === 1 => 'Gray',
            $transform === 1 && $components === 3 => 'RGB',
            $transform === 1 && $components === 4 => 'CMYK',
            default => 'Unknown',
        };
        return new NativeDescription($width, $height, $components, 8, new ColorInformation($space, iccProfile: $icc === [] ? null : implode('', $icc)), $request, $transform, $inspection);
    }
}
