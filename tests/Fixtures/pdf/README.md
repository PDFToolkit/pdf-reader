# PDF externes de test

Ces cinq petits documents sont créés indépendamment du writer PHP de ce dépôt.
Le texte, les dictionnaires et les données binaires sont des contenus originaux
du projet, distribués sous sa licence MIT. Aucun PDF tiers n'est repris.

`tools/build_reader_fixtures.py` crée le document de départ avec **pypdf 6.20.1**,
puis **qpdf 12.4.2** produit une représentation avec flux d'objets et flux xref.
Les autres variantes ajoutent des révisions traditionnelles : les offsets,
entrées xref et liens `/Prev` sont calculés directement en Python ; les valeurs
des objets et des trailers sont sérialisées par pypdf.

Les versions sont fixées, les identifiants de fichier sont déterministes et
aucune date variable n'est ajoutée. Les tests PHP lisent les binaires commités
et ne nécessitent ni Python, ni pypdf, ni qpdf.

| Fichier | Cas exercé |
| --- | --- |
| `classic.pdf` | PDF 1.7, xref traditionnel, une page Helvetica ; chaînes octales et hexadécimales, nom échappé, tableau et dictionnaire imbriqués ; flux de 297 octets dont `/Length` est la référence `7 0 R`. |
| `object-streams.pdf` | Même document réécrit par qpdf : catalogue dans le flux d'objets `1 0` (`/N 6`, `/First 34`) ; flux xref `10 0` avec `/W [1 2 1]`, Flate et prédicteur PNG 12 (`/Columns 4`). |
| `incremental.pdf` | Deux révisions ; l'objet Info `1 0` est remplacé, son titre devient `Updated revision` ; `/Prev` pointe sur l'ancien xref. Les octets de `classic.pdf` sont intégralement conservés en préfixe. |
| `deleted-object.pdf` | Deux révisions ; l'objet `9 0` devient libre, avec génération suivante 1 et liste libre mise à jour. La référence du catalogue vers `9 0 R` doit résoudre à null. |
| `reused-generation.pdf` | Trois révisions ; le numéro supprimé est réutilisé comme `9 1`, contenant `reused generation one`. `9 0 R` reste null ; `9 1 R` résout la nouvelle valeur. |

Chaque page contient le texte `Independent reader fixture` dans un flux de
contenu de 58 octets. Le catalogue contient aussi les 256 valeurs d'octet dans
`/FixtureBinary`, la chaîne `A (nested) \ line` suivie d'un saut de ligne, d'une
tabulation et de `end` dans `/FixtureLiteral`, et le nom `/A name/#()` dans
`/FixtureName`. `/FixtureData` désigne un flux binaire indépendant : ses octets
contiennent notamment `endstream`, `endobj`, `xref`, `startxref` et `%%EOF`.
Seule sa longueur réelle permet de le lire correctement.

`manifest.json` consigne les identifiants utiles, longueurs, hashes SHA-256 et
offsets du dernier xref. Les numéros changent dans la variante qpdf ; un lecteur
doit suivre les références plutôt que supposer les numéros du document initial.

## Reproduire et contrôler

Dans un environnement Python de développement, installer `pypdf==6.20.1` et
rendre qpdf 12.4.2 disponible, puis exécuter depuis la racine du dépôt :

```console
python tools/build_reader_fixtures.py --qpdf qpdf
python tools/build_reader_fixtures.py --qpdf qpdf --check
```

`--qpdf` accepte également le chemin complet de l'exécutable. `--check` recrée
les fichiers dans un dossier temporaire et compare leurs octets aux fixtures,
sans les modifier. Le script valide chaque fichier avec `qpdf --check`, puis
lit sa page, son texte et ses valeurs binaires avec `PdfReader(strict=True)`.
Il vérifie également les préfixes des révisions et le remplacement des
métadonnées. qpdf confirme explicitement les valeurs nulles des objets supprimés
et des références dont la génération n'est plus courante, ainsi que la valeur
de `9 1` après réutilisation.

pypdf 6.20.1 conserve une ancienne entrée utilisée lorsqu'il résout la génération
initiale d'un objet ensuite supprimé : il ne sert donc pas d'oracle pour ce cas.
L'état effectif attendu suit ISO 32000-1, 7.3.9 et 7.5.4–7.5.6, et est confirmé
avec qpdf. Les tests du lecteur PHP doivent exercer cette distinction.
