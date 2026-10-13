# Changelog

## Unreleased

## 0.1.2 — 2026-10-13

### Compatibilité

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4`.
- Conserve l'API du module et sa frontière de traitement des données externes.
- Vérifie le core stable 0.3.0 et les nouveaux objets du core 0.4 en développement,
  sous PHP 8.3, 8.4 et 8.5.

## 0.1.1 — 2026-10-13

### Compatibilité

- Accepte le core `roukmoute/pdf:^0.2 || ^0.3`, pour permettre l'installation
  avec les nouveaux objets PDF de la série 0.3 sans modifier l'API du module.
- Revalide les tests et les PDF indépendants avec le core stable `0.2.1`.
  Aucun mécanisme de lecture ou de conversion n'est ajouté par ce correctif.

## 0.1.0 — 2026-10-13

Première implémentation du lecteur de PDF externes, sous `PDF\Reading`, dans
le paquet `pdftoolkit/pdf-reader`. Le lecteur dépend du modèle partagé
`roukmoute/pdf:^0.2` ; aucune dépendance inverse n'est ajoutée au modèle.

### Ajouts

- Lecture structurelle de PDF 1.0 à 1.7 avec réutilisation des primitives objet.
- Parseur de syntaxe borné, objets indirects et streams à longueur directe ou indirecte.
- Tables xref traditionnelles, révisions `/Prev`, entrées libres et générations.
- Flux xref et objets comprimés `/ObjStm`, avec résolution explicite et caches documentaires.
- Parcours borné des arbres de pages, avec vérification des comptes, parents et identités.
- Objets immuables de pages lues et attributs effectifs : ressources, MediaBox, CropBox et rotation.
- Lecture ordonnée des flux de contenu d'une page, sans concaténation ni décodage implicites.
- Décodage Flate, ASCIIHex, ASCII85 et RunLength ; prédicteurs TIFF et PNG à composants de 8 bits.
- Limites configurables, budgets cumulatifs et diagnostics de structure ou de fonctionnalité non prise en charge.
- Cinq fixtures originales générées avec pypdf et qpdf, indépendamment du writer PHP.
- Deux fixtures indépendantes d'héritage de pages, en xref traditionnel et avec objets comprimés.
- Vérifications sur PHP 8.3, 8.4 et 8.5 ; documentation des composants et frontières de sécurité.

Les références hybrides, le chiffrement, PDF 2.0, l'import vers le writer,
la réécriture, l'extraction de texte et le rendu ne sont pas encore implémentés.
