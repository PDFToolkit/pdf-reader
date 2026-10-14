# Lire les pages et leur héritage

[English](../pages.md) | Français

Un catalogue PDF désigne, par `/Pages`, la racine d'un arbre. Ses branches sont
des dictionnaires `/Pages` ; ses feuilles sont des dictionnaires `/Page`.
Le tableau `/Kids` donne l'ordre des descendants. `/Count` annonce le nombre
de feuilles d'une branche, tandis que `/Parent` relie un descendant à son parent.
Lire le seul `/Count` de la racine ne suffit donc pas à vérifier les pages.
Ces règles viennent d'ISO 32000-1, 7.7.3 et des tableaux 29 et 30.

## Un parcours demandé explicitement

Après avoir obtenu un `ReadDocument` avec `DocumentReader::read()`, construire
le lecteur d'arbre avec ce document et les plafonds du parcours :

```php
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;

$tree = (new PageTreeReader($document, new PageTreeLimits(
    maxDepth: 64,
    maxNodes: 10_000,
    maxPages: 5_000,
)))->read();

foreach ($tree as $index => $page) {
    printf(
        "Page %d; effective rotation: %d\n",
        $index + 1,
        $page->attributes->rotate->integer(),
    );
}
```

`read()` suit les enfants dans l'ordre de `/Kids` et ne retourne sa collection
qu'après la vérification de l'arbre entier. Chaque branche doit annoncer le
nombre de feuilles réellement rencontrées. La racine ne doit pas avoir de
`/Parent` ; les autres branches et les pages doivent désigner leur parent
immédiat. Les cycles, références répétées et valeurs structurelles incohérentes
provoquent une `ParseException`.

`ReadPageTree` implémente `Countable` et `IteratorAggregate`. `count($tree)`
donne le nombre de pages validées ; `$tree->page(0)` donne la première page.
La collection expose sa référence racine, son document et ses pages.
Chaque `ReadPage` conserve une référence source, le dictionnaire résolu et le
document qui les possède. Les `PageAttributes` calculés sont des objets PDF :
un `DictionaryType` de ressources, deux `Rectangle` et un `IntegerType`.
Le parcours ne transforme pas ces valeurs en tableaux associatifs applicatifs.

## Les valeurs effectives d'une page

Quatre entrées peuvent être héritées : `/Resources`, `/MediaBox`, `/CropBox`
et `/Rotate`. Une valeur présente sur la page prévaut ; sinon, on cherche
l'ancêtre le plus proche qui la définit. Une entrée null est traitée comme
absente, conformément aux règles des dictionnaires PDF. `/Resources` et
`/MediaBox` sont obligatoires après cette recherche. `/CropBox` utilise
`/MediaBox` par défaut ;
`/Rotate` vaut zéro par défaut et doit être un multiple de 90 degrés.

L'héritage choisit une valeur complète. Un dictionnaire `/Resources` local ne
fusionne pas ses catégories avec celles du parent : même vide, il remplace
les ressources héritées. Le dictionnaire source doit rester consultable afin
de distinguer les entrées réellement écrites des valeurs effectives calculées.

`PageAttributes::rotate` conserve l'entier PDF d'origine. Sa méthode
`normalizedRotation()` donne une orientation de 0, 90, 180 ou 270 degrés : une
rotation de `-90` reste un `IntegerType(-90)` et correspond à 270 degrés.
Les coordonnées des rectangles sont également conservées. Le lecteur ne
calcule pas de transformation de dessin ni de position dans un écran.

Les autres entrées restent locales à la page. En particulier, `/Contents`
n'est pas hérité, pas plus que `/BleedBox`, `/TrimBox`, `/ArtBox` ou `/UserUnit`.
Les valeurs effectives de ces dernières entrées ne sont pas calculées par
ce parcours ; l'application peut consulter leur définition et leur valeur
source avec les objets du modèle partagé.

## Des flux de contenu distincts

Une page peut omettre `/Contents` : elle n'a alors aucun flux de contenu.
Lorsqu'il existe, cet objet désigne un seul flux ou un tableau de flux.
L'ordre du tableau fait partie du document. Un découpage entre deux flux
ne délimite pas forcément des opérations de dessin indépendantes : selon
ISO 32000-1, 7.8.2, leur effet correspond à une concaténation dans cet ordre.

Conserver les objets de flux et leur ordre permet de choisir ensuite quand
les décoder. Une demande de décodage produit des octets, sans interpréter
les opérateurs, résoudre les polices ou extraire le texte visible. Des données
compressées inconnues peuvent rester consultables sous leur forme encodée.

```php
use PDF\Reading\PageContentLimits;
use PDF\Reading\PageContentsReader;

$contentsReader = new PageContentsReader($document, new PageContentLimits(
    maxStreams: 128,
));

foreach ($tree as $pageIndex => $page) {
    $contents = $contentsReader->read($page);
    printf("Page %d; streams: %d\n", $pageIndex + 1, count($contents));

    foreach ($contents as $streamIndex => $stream) {
        $encodedBytes = $stream->encodedBytes();
        $decodedBytes = $document->streamData($stream);
        printf(
            "Stream %d; encoded: %d bytes; decoded: %d bytes\n",
            $streamIndex + 1,
            strlen($encodedBytes),
            strlen($decodedBytes),
        );
    }
}
```

`PageContents` garde la page propriétaire et une liste d'`EncodedStream`.
`read()` refuse une page appartenant à un autre `ReadDocument`. Une entrée
absente ou null et un tableau vide donnent une collection vide. Une entrée
non vide doit se résoudre à un flux ou à un tableau dont chaque élément se
résout à un flux ; aucun élément invalide n'est ignoré.

## Essayer avec les fixtures indépendantes

L'[exemple exécutable](../../examples/pages.php) utilise l'arbre à trois pages
créé avec pypdf et qpdf. Depuis un checkout du lecteur :

```console
composer install
php examples/pages.php
php examples/pages.php tests/Fixtures/page-tree/object-streams.pdf
```

La première page hérite d'une branche : sa rotation brute est `-90` et elle
possède deux flux. La deuxième définit ses propres ressources vides et une
rotation zéro ; son CropBox vient de la sous-branche et ses deux flux sont
référencés par un tableau indirect. La troisième reprend les attributs de
la racine, avec rotation 90, et n'a aucun flux. La variante qpdf représente
les mêmes pages dans un flux d'objets, avec d'autres numéros.
La [description des fixtures](../../tests/Fixtures/page-tree/README.md) donne
les valeurs effectives et leur origine.

## Limiter le travail de chaque opération

| Limite | Valeur par défaut | Portée |
| --- | --- | --- |
| `PageTreeLimits::maxDepth` | 64 | Profondeur de l'arbre, racine incluse. |
| `PageTreeLimits::maxNodes` | 100 000 | Nombre cumulé de branches et de feuilles visitées. |
| `PageTreeLimits::maxPages` | 100 000 | Nombre de feuilles et borne des comptes déclarés. |
| `PageContentLimits::maxStreams` | 1 024 | Nombre de flux dans le contenu d'une page. |

Ces plafonds s'appliquent à une opération. Les limites de `ReaderLimits` et
les budgets cumulatifs du document continuent de contrôler les objets chargés,
les valeurs analysées, les octets retenus et le décodage. Un arbre peut avoir
une grande profondeur avec des dictionnaires syntaxiquement peu imbriqués :
la profondeur d'arbre nécessite donc son propre plafond.
Le parcours utilise une pile explicite ; il n'appelle pas récursivement
la pile PHP pour chaque descendant.

## Portée des contrôles

Le parcours des pages vérifie la structure qu'il visite. Il ne rend pas le
document et ne vérifie pas toutes les annotations, ressources, polices ou
actions auxquelles ses pages peuvent faire référence. Ces références restent
des données du document source ; elles ne deviennent pas des objets importés
dans le writer.

[Architecture](architecture.md) · [Composants](components.md) ·
[Contrôles de sécurité](security.md) · [README](../../README.fr.md)
