<?php

declare(strict_types=1);

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    // Un package installé utilise l'autoloader de son projet consommateur.
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
if (!is_file($autoload)) {
    throw new RuntimeException('Install the Composer dependencies before running this example.');
}
require $autoload;

// 1. L'application choisit un fichier local. Le lecteur ne reçoit que ses octets.
$path = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/page-tree/classic.pdf';
if (str_contains($path, '://') || str_contains($path, "\0")) {
    throw new InvalidArgumentException('Choose a local PDF path.');
}
$bytes = file_get_contents($path);
if ($bytes === false) {
    throw new RuntimeException('Could not read the chosen PDF.');
}
$document = (new DocumentReader())->read(new FileBytes($bytes));

// 2. Cette opération parcourt tout l'arbre : Count et Parent sont vérifiés,
//    l'ordre de Kids est conservé, les quatre attributs héritables sont résolus.
//    Ces limites bornent le parcours indirect, indépendamment de la syntaxe.
$tree = (new PageTreeReader($document, new PageTreeLimits(maxDepth: 64, maxNodes: 10000, maxPages: 5000)))->read();
printf("Verified pages: %d\n", count($tree));
$contentReader = new PageContentsReader($document, new PageContentLimits(maxStreams: 128));
foreach ($tree as $index => $page) {
    // 3. Les valeurs effectives sont des objets du core. Le dictionnaire source
    //    garde ses seules entrées locales ; il n'est ni modifié ni importé.
    printf(
        "Page %d (%s R): MediaBox %s; CropBox %s; Rotate %d (orientation %d); resource categories %d\n",
        $index + 1,
        $page->reference->identifier,
        $page->attributes->mediaBox,
        $page->attributes->cropBox,
        $page->attributes->rotate->integer(),
        $page->attributes->normalizedRotation(),
        count($page->attributes->resources),
    );

    // 4. Contents appartient seulement à cette page. L'inspection conserve les
    //    flux séparés dans leur ordre, y compris leurs filtres et octets encodés.
    $contents = $contentReader->read($page);
    printf("  Content streams: %d\n", count($contents));
    foreach ($contents as $ordinal => $stream) {
        // Le décodage est demandé ici, sous les budgets cumulés du document.
        // Les octets résultants restent du langage PDF, sans extraction de texte.
        $decoded = $document->streamData($stream);
        printf("  Stream %d: %d encoded bytes, %d decoded bytes\n", $ordinal + 1, strlen($stream->encodedBytes()), strlen($decoded));
    }
}
