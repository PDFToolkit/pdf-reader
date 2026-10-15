# PDF Reader

[English](README.md) | Français

Lecteur PHP de PDF externes, construit autour des objets de **ISO 32000-1:2008**
et **ISO 32000-2:2020**.
Il expose les dictionnaires, tableaux, références et flux du document avec une
résolution explicite. PHP **8.3 minimum** ; la CI exécute les vérifications sur
PHP **8.3, 8.4 et 8.5**.

Ce dépôt complète [PDFToolkit/pdf](https://github.com/PDFToolkit/pdf), qui fournit
les primitives et le writer. `pdftoolkit/pdf-reader` dépend de ce modèle ; le
modèle ne dépend pas du lecteur. Les deux paquets utilisent les mêmes objets
pour les noms, chaînes, nombres, tableaux, dictionnaires et identifiants.

## Installer

Le package est disponible sur
[Packagist](https://packagist.org/packages/pdftoolkit/pdf-reader). Dans le projet
consommateur :

```console
composer require "pdftoolkit/pdf-reader:^0.3"
```

Le lecteur utilise le core `roukmoute/pdf:^0.7.1 || ^0.8`,
installé automatiquement par Composer. PHP 8.3 ou supérieur sur une plateforme
64 bits et les extensions `ctype`, `iconv` et `zlib` sont requis pour cet ensemble.
Le fichier `composer.lock` du projet consommateur fixe les versions et les
révisions utilisées.

La version 0.3 accepte la syntaxe PDF 1.0–1.7 et PDF 2.0, y compris la version
`/Version /2.0` du catalogue, avec les mêmes lecteurs bornés d’objets et de flux.
Les dictionnaires PDF 2.0 deviennent accessibles aux interprètes optionnels,
dont `pdftoolkit/pdf-to-html` ; cela n’interprète pas chaque mécanisme PDF 2.0.
L’authentification Standard par mot de passe est explicite ; les autres handlers
de sécurité, codecs non pris en charge, rendu et import restent des limites.
Consulter le [guide d’authentification](docs/fr/authentication.md).

## Lire les pages

Cet exemple se lance depuis un checkout du lecteur après `composer install`.
Le chargement du fichier est effectué par l'application ; le lecteur reçoit
uniquement ses octets.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$bytes = file_get_contents(__DIR__ . '/tests/Fixtures/page-tree/classic.pdf');
if ($bytes === false) {
    throw new RuntimeException('Could not read the fixture.');
}

$document = (new DocumentReader())->read(new FileBytes($bytes));
$tree = (new PageTreeReader($document, new PageTreeLimits(
    maxDepth: 64,
    maxNodes: 10_000,
    maxPages: 5_000,
)))->read();
printf("Validated page count: %d\n", count($tree));

foreach ($tree as $index => $page) {
    // These are the effective PDF values, after page-tree inheritance.
    $attributes = $page->attributes;
    printf(
        "Page %d; rotation: %d; normalized rotation: %d\n",
        $index + 1,
        $attributes->rotate->integer(),
        $attributes->normalizedRotation(),
    );
    foreach ($attributes->resources->entries() as $entry) {
        printf("Resource category: %s\n", $entry->key->value);
    }
}
```

`PageTreeReader::read()` parcourt les branches `/Pages` et les feuilles `/Page`
dans l'ordre de `/Kids`. Il vérifie chaque `/Count`, les relations `/Parent`,
l'unicité des descendants et les limites du parcours. Le `ReadPageTree` obtenu
est comptable et itérable ; `page(0)` désigne sa première page. Chaque `ReadPage`
garde son document, sa référence et son dictionnaire source, puis expose ses
`PageAttributes` effectifs : ressources, MediaBox, CropBox et rotation.

`DictionaryType::get()` retourne toujours la valeur stockée : une référence
reste un `ObjectReference`. `ReadDocument::resolve()` charge cette référence dans le
document concerné et laisse une valeur directe inchangée. Les objets déjà lus
sont conservés dans un cache propre au document ; aucun registre global,
singleton ou résolution cachée dans les valeurs.

Les attributs hérités utilisent l'ancêtre le plus proche qui les définit.
Un dictionnaire de ressources local remplace les ressources héritées, sans
fusion. Le [guide des pages](docs/fr/pages.md) explique cet héritage et distingue
les valeurs écrites des valeurs effectives. Le parcours ne charge pas les
contenus ni toutes les ressources de chaque page.

## Lire les octets d'un flux

`PageContentsReader` résout `/Contents` en une collection ordonnée de flux
encodés. Il reçoit le document et sa propre limite de flux par page.
À la suite de l'exemple précédent, pour la première page de cette fixture :

```php
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;

$page = $tree->page(0);
$contents = (new PageContentsReader($document, new PageContentLimits(
    maxStreams: 128,
)))->read($page);

foreach ($contents as $stream) {
    $encodedBytes = $stream->encodedBytes();
    // Decoding is requested explicitly, under the document's shared budgets.
    $decodedBytes = $document->streamData($stream);
}
```

Une page sans `/Contents`, avec null ou un tableau vide donne une collection
vide. Les flux d'un tableau sont conservés dans leur ordre, sans concaténation
ni décodage automatiques. Un `EncodedStream` garde son dictionnaire et les octets
prêts au décodage ; `originalEncodedBytes()` conserve le ciphertext source après
authentification. `streamData()` demande le décodage. Le résultat reste une suite
d'octets : les opérateurs de dessin, polices et encodages ne sont pas interprétés
pour extraire du texte ou rendre une page.

## Couverture actuelle

| Mécanisme | Lecture disponible |
| --- | --- |
| Syntaxe | Noms et chaînes échappés, entiers et réels, booléens, null, tableaux, dictionnaires, références indirectes. |
| Structure | Headers et versions de catalogue PDF 1.0–1.7 et 2.0 ; objets indirects et flux délimités par `/Length`, y compris une longueur indirecte. |
| Xref traditionnel | Sous-sections, offsets, générations, objets libres et révisions liées par `/Prev`. |
| Flux xref | Champs `/W`, plages `/Index`, types libres, directs ou comprimés ; types inconnus donnant un objet indisponible. |
| Xref hybrides | Les entrées `/XRefStm` prévalent sur la table associée ; le trailer de la table porte `/Prev` ; budgets cumulés pour les deux. |
| Flux d'objets | Membres de `/ObjStm`, avec validation de l'en-tête, des offsets et du numéro attendu. |
| Filtres | FlateDecode, LZWDecode (EarlyChange 0/1), ASCIIHexDecode, ASCII85Decode et RunLengthDecode ; chaînes de filtres et paramètres explicites. |
| Prédicteurs | TIFF 2 et PNG 10 à 15, avec composants de 1, 2, 4, 8 et 16 bits. |
| Résolution | Un objet absent, libre ou de génération différente donne `NullType`. |
| Arbre de pages | Parcours ordonné complet, vérification de chaque `/Count` et `/Parent`, refus des cycles ou descendants répétés, limites propres au parcours. |
| Héritage | Ressources, MediaBox, CropBox et rotation effectifs ; CropBox utilise MediaBox par défaut et rotation zéro si absente. |
| Contenu des pages | Un flux ou un tableau de flux, conservés dans l'ordre ; lecture et décodage séparés. |

Sans credentials explicites, les fichiers chiffrés lèvent `EncryptedPdfException`.
L’authentification native Standard prend en charge R2–R7 et les crypt filters
nommés. La vérification des PDF MAC source et l’authentification par clé publique
restent à implémenter. Un codec inconnu peut être conservé encodé ; son décodage
lève `UnsupportedPdfFeature`.

L'import vers le writer, la réécriture, la fusion, l'extraction de texte, le
rendu, les formulaires, les signatures et la réparation des PDF restent des
mécanismes à construire. Les références et flux externes refusent leur
sérialisation : réutiliser leurs numéros dans un nouveau document serait
incorrect sans import et remappage explicites.

## Développement

```console
composer install
composer check
composer audit --locked
```

Les tests couvrent la syntaxe, les filtres, les structures, les pages et les
budgets. Les [cinq fixtures de structure](tests/Fixtures/pdf/README.md) et les
[deux fixtures de pages](tests/Fixtures/page-tree/README.md) sont
générées avec pypdf et qpdf, indépendamment du writer PHP ; leurs octets et leurs
résultats sont vérifiés lors de la reproduction.

Le lecteur vérifie une structure lorsqu'elle est chargée. Ouvrir le catalogue
n'effectue pas un audit exhaustif de tous les objets du fichier. Consulter les
[contrôles de sécurité et leurs limites](docs/fr/security.md) avant de choisir les
budgets d'un service recevant des uploads.

## Documentation

- [Architecture et frontières des objets](docs/fr/architecture.md)
- [Pages, héritage et flux de contenu](docs/fr/pages.md)
- [Référence des composants](docs/fr/components.md)
- [Contrôles de sécurité](docs/fr/security.md)
- [Politique de signalement](SECURITY.fr.md)
- [Changelog](CHANGELOG.fr.md)
- [Documentation du module lecteur](https://pdftoolkit.github.io/fr/modules/reader/)
- [Documentation commune des objets, du writer et des modules](https://pdftoolkit.github.io/fr/)
- [Norme PDF 1.7 incluse dans le dépôt du modèle](https://pdftoolkit.github.io/downloads/iso-32000-1.pdf)

Licence [MIT](LICENSE).
