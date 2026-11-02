# Fixtures indépendantes d'inspection

[English](README.md)

Ces fixtures originales sont distribuées sous la licence MIT du dépôt.
`tools/build_inspection_fixtures.py` écrit leur syntaxe PDF physique indépendamment
de PDFToolkit. qpdf **12.4.2** produit la variante avec flux d'objets et identifiant
statique ; le manifeste consigne la taille et le SHA-256 de chaque fichier.
Aucun document externe ni résultat du générateur PDFToolkit n'est utilisé.

| Fichier | Objectif |
| --- | --- |
| `classic.pdf` | Deux pages, un élément de structure de génération 7, un tableau `/K` indirect, des enfants MCID/MCR/OBJR ordonnés, les propriétés et références originales, les associations de pages héritées et explicites. |
| `object-streams.pdf` | Réécriture indépendante du même document par qpdf, avec objets compressés et références renumérotées. |
| `untagged.pdf` | Inspection des pages et pièces jointes sans arbre de structure ni dérivation HTML réussie. |
| `cycles-and-missing.pdf` | Cycle vers un ancêtre, élément répété, objet absent, objet libre et référence utilisant une génération incorrecte. Structure volontairement malformée. |

Les documents balisés contiennent une table de correspondance de rôles à la
racine et deux dictionnaires d'espaces de noms distincts avec **la même URI** mais
des correspondances différentes. Des fichiers associés figurent dans le
catalogue, une page et un élément de structure ; un quatrième fichier figure
dans un arbre de noms `EmbeddedFiles` imbriqué. Les métadonnées de leurs flux
comprennent un filtre inconnu et une adresse de flux externe. L'inspection ne
doit ni décoder ni acquérir ces flux. Une action JavaScript du catalogue, une
action URI et des chaînes ressemblant à du HTML restent des propriétés source
inertes, jamais des instructions exécutables.

Les chaînes binaires, UTF-16BE et UTF-8, les noms PDF échappés, les valeurs nulles,
les booléens, les réels et les entiers signés dépassant la précision entière de
JavaScript vérifient la conservation des types et des octets PDF. La comparaison
porte sur les valeurs parsées originales, pas sur l'écriture lexicale initiale
des espaces ou des nombres.

Reproduction et validation depuis la racine du dépôt :

```sh
python tools/build_inspection_fixtures.py --check
python tools/validate_inspection.py --report _build/inspection-validation.json
```

Utiliser `--qpdf /chemin/vers/qpdf` et `--php /chemin/vers/php` si nécessaire.
Retirer `--check` uniquement pour régénérer volontairement les fixtures. L'oracle
parse la source avec la dépendance pypdf épinglée séparément, compare le JSON
réel de `DocumentInspector`, vérifie la syntaxe et les objets compressés avec
qpdf, contrôle le SHA-256 avant et après inspection et exerce de petits budgets
cumulatifs. Les octets des flux ne figurent jamais dans le JSON d'inspection.
pypdf retire `/Length` des dictionnaires de flux parsés ; l'oracle vérifie cette
propriété indépendamment à partir de la longueur des octets bruts préservés.
Le contrôle syntaxique de qpdf ne certifie ni la conformité PDF/UA ni la
sémantique de l'arbre de structure.
