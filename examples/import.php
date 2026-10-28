<?php

declare(strict_types=1);

use PDF\FileStructure;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Import\ImportLimits;
use PDF\Reading\Import\ImportSession;
use PDF\Reading\Import\StreamImportMode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\IndirectReference;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
require $autoload;

/** The example application owns all paths; import itself receives only PDF objects. */
final readonly class ImportExample
{
    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        if (count($arguments) !== 4 || !in_array($arguments[3], ['1.7', '2.0'], true)) {
            throw new InvalidArgumentException('Usage: php examples/import.php input.pdf new-output.pdf 1.7|2.0');
        }
        [, $input, $output, $version] = $arguments;
        foreach ([$input, $output] as $path) {
            if ($path === '' || str_contains($path, '://') || str_contains($path, "\0")) {
                throw new InvalidArgumentException('Choose explicit local paths.');
            }
        }
        if (file_exists($output) || is_link($output) || $input === $output) {
            throw new InvalidArgumentException('The output must be a new file distinct from the source.');
        }
        $bytes = file_get_contents($input, false, null, 0, 67108865);
        if ($bytes === false || strlen($bytes) > 67108864) {
            throw new RuntimeException('The selected PDF is unreadable or exceeds the example input limit.');
        }
        $document = (new DocumentReader())->read(new FileBytes($bytes));
        $sourceRoot = $document->trailer()->get('Root');
        if ($sourceRoot === null) {
            throw new LogicException('The source catalog reference is missing.');
        }
        $root = (new ImportSession($document, StreamImportMode::PreserveEncoded, new ImportLimits()))->import($sourceRoot)->root();
        if (!$root instanceof IndirectReference) {
            throw new LogicException('Expected an imported native catalog reference.');
        }
        $native = (new FileStructure(new FileHeader($version), new FileBody(), $root))->toPdf();
        $handle = fopen($output, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create the new output file.');
        }
        try {
            $offset = 0;
            while ($offset < strlen($native)) {
                $written = fwrite($handle, substr($native, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot finish the new output.');
                }
                $offset += $written;
            }
        } finally {
            fclose($handle);
        }
        echo json_encode(['bytes' => strlen($native), 'sha256' => hash('sha256', $native), 'sourceSha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR), "\n";
    }
}

(new ImportExample())->run(isset($argv) ? $argv : []);
