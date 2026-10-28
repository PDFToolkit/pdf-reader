<?php

declare(strict_types=1);

namespace PDF\Reading\Tests\Support;

use PDF\Reading\FileBytes;

/** Physical PDF syntax independent of the writer under test. */
final readonly class ImportSource
{
    /** @param array<int, string> $objects
     * @param array<int, int> $generations
     */
    public function file(array $objects = [], array $generations = []): FileBytes
    {
        $objects += [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [90 0 R] /Count 1 >>', 90 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources <<>> >>'];
        ksort($objects);
        $bytes = "%PDF-2.0\n";
        $offsets = [];
        foreach ($objects as $number => $value) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number . ' ' . ($generations[$number] ?? 0) . " obj\n" . $value . "\nendobj\n";
        }
        $xref = strlen($bytes);
        $size = max(array_keys($objects)) + 1;
        $bytes .= 'xref' . "\n0 " . $size . "\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; ++$number) {
            $bytes .= isset($offsets[$number]) ? sprintf("%010d %05d n \n", $offsets[$number], $generations[$number] ?? 0) : "0000000000 00000 f \n";
        }
        return new FileBytes($bytes . 'trailer << /Size ' . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
    }

    public function stream(string $bytes, string $entries = ''): string
    {
        return '<< /Length ' . strlen($bytes) . ' ' . $entries . " >>\nstream\n" . $bytes . "\nendstream";
    }
}
