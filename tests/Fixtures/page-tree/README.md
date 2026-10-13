# Arbre de pages externe

Ces deux PDF 1.7 sont des contenus originaux du projet, sous licence MIT. Ils
sont produits avec **pypdf 6.20.1** puis réécrits avec **qpdf 12.4.2**, sans
utiliser le writer PHP de PDFToolkit. Les tests PHP peuvent lire les fichiers
commités sans ces outils.

L'arbre comporte six nœuds et trois pages, dans cet ordre :

```text
Pages racine (Count 3)
├── Pages branche (Count 2)
│   ├── Page 1
│   └── Pages sous-branche (Count 1)
│       └── Page 2
└── Page 3
```

| Page | MediaBox effective | CropBox effective | Rotate effectif | Resources effectives |
| --- | --- | --- | --- | --- |
| 1 | `[0 0 300 500]`, branche | `[20 30 280 470]`, branche | `-90`, branche | `FBranch` : Courier, branche |
| 2 | `[0 0 320 520]`, page | `[30 40 270 460]`, sous-branche | `0`, page | Dictionnaire vide, page |
| 3 | `[0 0 400 600]`, racine | `[10 20 390 580]`, racine | `90`, racine | `FRoot` : Helvetica, racine |

La première page vérifie que l'ancêtre le plus proche remplace les valeurs de
la racine. La deuxième vérifie à la fois un héritage sur plusieurs niveaux,
l'arrêt de cet héritage par `Rotate 0` et par un dictionnaire `Resources` vide.
La troisième vérifie que les valeurs de la branche précédente ne contaminent
pas son frère. Le rectangle `MediaBox` de la branche est indirect.

La page 1 contient un tableau direct de deux flux de texte, l'un brut et
l'autre Flate. La page 2 utilise un tableau indirect de deux flux : une image
en ligne en niveaux de gris, puis des opérateurs graphiques compressés. Les
297 octets de pixels comprennent toutes les valeurs de `0x00` à `0xff`, ainsi
que `endstream`, `endobj`, `xref`, `startxref` et `%%EOF`. Il faut donc suivre
les longueurs déclarées des flux. La page 3 omet `Contents`.

`classic.pdf` utilise un xref traditionnel. `object-streams.pdf` représente le
même arbre avec un xref stream et les dictionnaires des pages dans un flux
d'objets. qpdf peut modifier les numéros d'objets et compresser les flux ; les
tests suivent les identifiants propres à chaque fichier dans `manifest.json`.
Le manifeste consigne leurs SHA-256, les identités des nœuds, les entrées
propres aux pages, les valeurs héritées attendues, les encodages et tous les
octets bruts et décodés des contenus, dans l'ordre.

Pour reproduire et vérifier depuis la racine du dépôt :

```console
python tools/build_page_tree_fixture.py --qpdf qpdf
python tools/build_page_tree_fixture.py --qpdf qpdf --check
python tools/validate_page_tree_fixture.py --qpdf qpdf
```

Le générateur fixe les identifiants de fichier et n'insère aucune date. Avec
les versions indiquées, `--check` compare chaque PDF et le manifeste octet par
octet, dans un répertoire temporaire. Le validateur confronte les références,
la structure et les données à des attentes explicites, vérifie chaque fichier
avec `qpdf --check`, puis confirme l'héritage et l'extraction du texte avec
`PdfReader(strict=True)`. Il contrôle aussi avec qpdf que les trois feuilles de
la variante réécrite résident effectivement dans un flux d'objets.
