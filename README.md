# PDF Reader

Lecteur PHP de PDF externes, construit autour des objets de **ISO 32000-1:2008**.
Il expose les dictionnaires, tableaux, références et flux du document avec une
résolution explicite. PHP **8.3 minimum** ; la CI exécute les vérifications sur
PHP **8.3, 8.4 et 8.5**.

Ce dépôt complète [PDFToolkit/pdf](https://github.com/PDFToolkit/pdf), qui fournit
les primitives et le writer. `pdftoolkit/pdf-reader` dépend de ce modèle ; le
modèle ne dépend pas du lecteur. Les deux paquets utilisent les mêmes objets
pour les noms, chaînes, nombres, tableaux, dictionnaires et identifiants.

## Installer

Les paquets ne sont pas encore publiés sur Packagist. Ajouter les deux dépôts
VCS au projet Composer :

```console
composer config repositories.pdf vcs https://github.com/PDFToolkit/pdf
composer config repositories.pdf-reader vcs https://github.com/PDFToolkit/pdf-reader
composer require roukmoute/pdf:dev-master pdftoolkit/pdf-reader:dev-master
```

La dépendance `roukmoute/pdf:dev-master` est provisoire : cette branche contient
le modèle compatible PHP 8.3. La version publiée `v0.1.1` demande PHP 8.5.
Le fichier `composer.lock` du projet consommateur fixe les révisions utilisées.

## Lire un graphe d'objets

Cet exemple se lance depuis un checkout du lecteur après `composer install`.
Le chargement du fichier est effectué par l'application ; le lecteur reçoit
uniquement ses octets.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

$bytes = file_get_contents(__DIR__ . '/tests/Fixtures/pdf/classic.pdf');
if ($bytes === false) {
    throw new RuntimeException('Could not read the fixture.');
}

$document = (new DocumentReader())->read(new FileBytes($bytes));
$catalog = $document->catalog();
$pages = $document->resolve($catalog->get('Pages')
    ?? throw new UnexpectedValueException('Missing Pages.'));
if (!$pages instanceof DictionaryType) {
    throw new UnexpectedValueException('Pages must resolve to a dictionary.');
}

$count = $pages->get('Count');
if (!$count instanceof IntegerType) {
    throw new UnexpectedValueException('Count must be an integer.');
}
printf("Declared page count: %d\n", $count->integer());

$kids = $document->resolve($pages->get('Kids')
    ?? throw new UnexpectedValueException('Missing Kids.'));
if (!$kids instanceof ArrayType || $kids->items === []) {
    throw new UnexpectedValueException('Kids must be a nonempty array.');
}

// This fixture's first child is a leaf page. Other documents can have nested trees.
$page = $document->resolve($kids->items[0]);
if (!$page instanceof DictionaryType) {
    throw new UnexpectedValueException('The child must resolve to a dictionary.');
}
$resources = $document->resolve($page->get('Resources')
    ?? throw new UnexpectedValueException('Missing local Resources.'));
if (!$resources instanceof DictionaryType) {
    throw new UnexpectedValueException('Resources must resolve to a dictionary.');
}

foreach ($resources->entries() as $entry) {
    printf("Resource category: %s\n", $entry->key->value);
}
```

`DictionaryType::get()` retourne la valeur stockée : une référence reste un
`ObjectReference`. `ReadDocument::resolve()` charge cette référence dans le
document concerné et laisse une valeur directe inchangée. Les objets déjà lus
sont conservés dans un cache propre au document ; aucun registre global,
singleton ou résolution cachée dans les valeurs.

`/Count` est une déclaration du PDF. L'exemple ne recompte pas les feuilles,
ne parcourt pas tout l'arbre et ne calcule pas l'héritage des ressources.
La référence des [composants](docs/components.md) décrit les opérations
disponibles pour construire ces parcours explicitement.

## Lire les octets d'un flux

Un `EncodedStream` conserve son dictionnaire et les octets d'origine. Son
chargement ne décode pas automatiquement son contenu. À la suite de l'exemple
précédent :

```php
use PDF\Reading\EncodedStream;

$contents = $document->resolve($page->get('Contents')
    ?? throw new UnexpectedValueException('Missing Contents.'));
if (!$contents instanceof EncodedStream) {
    throw new UnexpectedValueException('This example expects a single content stream.');
}

$encodedBytes = $contents->encodedBytes();
$decodedBytes = $document->streamData($contents);
```

Dans d'autres documents, `/Contents` peut être un tableau de références à des
flux. Le résultat décodé reste une suite d'octets : les opérateurs de dessin,
polices et encodages ne sont pas interprétés pour extraire du texte ou rendre
une page.

## Couverture actuelle

| Mécanisme | Lecture disponible |
| --- | --- |
| Syntaxe | Noms et chaînes échappés, entiers et réels, booléens, null, tableaux, dictionnaires, références indirectes. |
| Structure | Headers PDF 1.0 à 1.7 ; objets indirects et flux délimités par `/Length`, y compris une longueur indirecte. |
| Xref traditionnel | Sous-sections, offsets, générations, objets libres et révisions liées par `/Prev`. |
| Flux xref | Champs `/W`, plages `/Index`, types libres, directs ou comprimés ; types inconnus donnant un objet indisponible. |
| Flux d'objets | Membres de `/ObjStm`, avec validation de l'en-tête, des offsets et du numéro attendu. |
| Filtres | FlateDecode, ASCIIHexDecode, ASCII85Decode et RunLengthDecode ; chaînes de filtres et paramètres explicites. |
| Prédicteurs | TIFF 2 et PNG 10 à 15, avec composants de 8 bits. |
| Résolution | Un objet absent, libre ou de génération différente donne `NullType`. |

Les fichiers chiffrés, les références hybrides `/XRefStm`, PDF 2.0 et les codecs
non implémentés sont refusés explicitement. Un flux utilisant un codec inconnu
peut être conservé sous forme encodée ; demander son décodage provoque
`UnsupportedPdfFeature`.

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

Les tests couvrent la syntaxe, les filtres, les structures, les budgets et les
cinq [fixtures externes](tests/Fixtures/pdf/README.md). Ces fixtures sont
générées avec pypdf et qpdf, indépendamment du writer PHP ; leurs octets et leurs
résultats sont vérifiés lors de la reproduction.

Le lecteur vérifie une structure lorsqu'elle est chargée. Ouvrir le catalogue
n'effectue pas un audit exhaustif de tous les objets du fichier. Consulter les
[contrôles de sécurité et leurs limites](docs/security.md) avant de choisir les
budgets d'un service recevant des uploads.

## Documentation

- [Architecture et frontières des objets](docs/architecture.md)
- [Référence des composants](docs/components.md)
- [Contrôles de sécurité](docs/security.md)
- [Politique de signalement](SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Documentation commune des objets, du writer et des modules](https://pdftoolkit.github.io/pdf/)
- [Norme PDF 1.7 incluse dans le dépôt du modèle](https://github.com/PDFToolkit/pdf/blob/master/PDF32000_2008.pdf)

Licence [MIT](LICENSE).
