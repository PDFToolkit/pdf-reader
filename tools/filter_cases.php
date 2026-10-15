<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\EncodedStream;
use PDF\Reading\FileBytes;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Filters\FixedPngFilterSelection;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\LZWDecode;
use PDF\Syntax\Filters\PngPredictor;
use PDF\Syntax\Filters\PngRowFilter;
use PDF\Syntax\Filters\PredictorBitsPerComponent;
use PDF\Syntax\Filters\PredictorLayout;
use PDF\Syntax\Filters\TiffPredictor;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

$directory = $argv[1] ?? throw new InvalidArgumentException('An explicit output directory is required.');
$cases = [];
$random = '';
for ($index = 0; $index < 1600; ++$index) {
    $random .= hash('sha256', pack('N', $index), true);
}
foreach ([0, 1] as $earlyChange) {
    foreach (['empty' => '', 'dictionary-reset' => $random, 'repeated' => str_repeat('ABABA', 4000)] as $label => $input) {
        $cases[] = ["lzw-$earlyChange-$label", new LZWDecode(new EarlyChange(new IntegerType($earlyChange))), $input];
    }
}
foreach (PredictorBitsPerComponent::cases() as $depth) {
    $layout = new PredictorLayout(new IntegerType(3), new IntegerType(7), $depth);
    $input = substr(str_repeat("\x05\xff\xa1\x92\x00\x13", $layout->bytesPerRow), 0, 3 * $layout->bytesPerRow);
    // Unused TIFF padding bits have no sample meaning; use zero for byte-exact cross-reader comparison.
    $padding = (8 - ($layout->samplesPerRow * $depth->value % 8)) % 8;
    for ($row = 1; $row <= 3; ++$row) {
        $last = $row * $layout->bytesPerRow - 1;
        $input[$last] = chr(ord($input[$last]) & ((255 << $padding) & 255));
    }
    $predictors = [new TiffPredictor($layout), new PngPredictor($layout, new FixedPngFilterSelection(PngRowFilter::cases()[array_search($depth, PredictorBitsPerComponent::cases(), true)]))];
    foreach ($predictors as $number => $predictor) {
        foreach (['flate' => new FlateDecode(predictor: $predictor), 'lzw' => new LZWDecode(predictor: $predictor)] as $label => $encoder) {
            $cases[] = ["$label-$number-{$depth->value}", $encoder, $input];
        }
    }
}
$manifest = [];
foreach ($cases as [$label, $encoder, $input]) {
    $encoded = $encoder->encode($input);
    $dictionary = new DictionaryType(new Entry(new NameType('Length'), new IntegerType(strlen($encoded))),
        new Entry(new NameType('Filter'), $encoder->name()), new Entry(new NameType('DecodeParms'), $encoder->parameters()));
    $bytes = "%PDF-1.7\n";
    $offsets = [];
    foreach ([1 => '<< /Type /Catalog /Pages 2 0 R /Fixture 3 0 R >>', 2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
        3 => (string) $dictionary . "\nstream\n" . $encoded . "\nendstream",
        4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << >> >>'] as $number => $value) {
        $offsets[$number] = strlen($bytes);
        $bytes .= "$number 0 obj\n$value\nendobj\n";
    }
    $xref = strlen($bytes);
    $bytes .= "xref\n0 5\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $bytes .= sprintf("%010d 00000 n \n", $offset);
    }
    $bytes .= "trailer << /Size 5 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    $document = (new DocumentReader())->read(new FileBytes($bytes));
    $stream = $document->object(new ObjectIdentifier(new ObjectNumber(3)));
    if (!$stream instanceof EncodedStream || $document->streamData($stream) !== $input || $stream->encodedBytes() !== $encoded) {
        throw new RuntimeException('Native filter result or preserved encoded bytes disagree.');
    }
    file_put_contents($directory . '/' . $label . '.pdf', $bytes);
    $manifest[] = ['file' => $label . '.pdf', 'object' => 3, 'decodedSha256' => hash('sha256', $input), 'decodedBytes' => strlen($input)];
}
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
