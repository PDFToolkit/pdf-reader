<?php

declare(strict_types=1);

namespace PDF\Reading\Tests\ImageCodecs;

use PDF\Reading\FileBytes;

final class FixtureFactory
{
    public function pdf(string $image, string $filter = 'DCTDecode', string $globals = ''): FileBytes
    {
        $bodies = ['<< /Type /Catalog /Pages 2 0 R /TestImage 3 0 R >>', '<< /Type /Pages /Kids [] /Count 0 >>',
            '<< /Type /XObject /Subtype /Image /Width 8 /Height 1 /BitsPerComponent 1 /ColorSpace /DeviceGray /Filter /' . $filter
            . ($globals === '' ? '' : ' /DecodeParms << /JBIG2Globals 4 0 R >>') . ' /Length ' . strlen($image) . ">>\nstream\n" . $image . "\nendstream"];
        if ($globals !== '') {
            $compressed = gzcompress($globals);
            $bodies[] = '<< /Filter /FlateDecode /Length ' . strlen($compressed) . ">>\nstream\n" . $compressed . "\nendstream";
        }
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($bodies as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $offset = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($bodies) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $position) {
            $pdf .= sprintf('%010d 00000 n ', $position) . "\n";
        }
        $pdf .= 'trailer << /Size ' . (count($bodies) + 1) . " /Root 1 0 R >>\nstartxref\n" . $offset . "\n%%EOF\n";
        return new FileBytes($pdf);
    }
}
