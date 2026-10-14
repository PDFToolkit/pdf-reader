# Changelog

[English](CHANGELOG.md) | Français

## Unreleased

## 0.2.0 — 2026-10-14

### Syntaxe PDF 2.0

- Accepte les headers PDF 2.0 et la version `/Version /2.0` du catalogue avec les lecteurs bornés existants de syntaxe, de références croisées et de flux d’objets.
- Conserve le refus explicite des entrées chiffrées, filtres non pris en charge et versions futures inconnues ; l’extraction sémantique reste dans les modules optionnels.
- Vérifie trois fixtures originales et indépendantes pypdf/qpdf, dont les flux d’objets PDF 2.0 et chaînes avec BOM UTF-8.
- Met à jour les documentations anglaises et françaises du périmètre, de l’architecture et de la sécurité.

## 0.1.9 — 2026-10-14

### Documentation

- Relie les README anglais et français au téléchargement stable de la norme PDF 1.7 sur le site documentaire, indépendamment de son emplacement dans le dépôt du core.
- Conserve l’API du lecteur, son comportement, les contraintes de dépendances et leurs versions verrouillées.

## 0.1.8 — 2026-10-14

### Compatibilité et périmètre

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7`.
- Verrouille les vérifications de publication sur le core publié `0.6.2`, avec des dépendances de production stables et les mêmes versions d’outils de développement.
- Conserve l’API du lecteur, les limites cumulatives et la couverture des entrées PDF 1.0 à 1.7. Les fonctionnalités du writer 0.7 n’ajoutent ni analyse PDF 2.0, ni déchiffrement, ni import.
- Exige PHP 8.3 ou supérieur ; la CI valide PHP 8.3, 8.4 et 8.5 ainsi que les oracles PDF indépendants.

## 0.1.7 — 2026-10-14

### Compatibilité

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6`.
- Verrouille les validations de publication sur le core publié `0.5.5`, sans modifier les versions ou commits des outils de développement.
- Vérifie le module inchangé avec le candidat du core 0.6 sous PHP 8.3, 8.4 et 8.5.
- Conserve l’API du lecteur, ses limites et sa couverture des entrées PDF 1.0 à 1.7 ; la lecture de PDF 2.0 n’est pas ajoutée.

## 0.1.6 — 2026-10-14

### Documentation bilingue

- Utilise l'anglais par défaut pour le README, les guides et l'historique complet.
- Ajoute leurs versions françaises intégrales avec liens de langue réciproques et entrées documentaires françaises.
- Traduit les commentaires pédagogiques des exemples en conservant les tokens exécutés et données de sortie.
- Verrouille les validations sur le core publié 0.5.4 sans changer les autres versions ou commits de dépendances.
- Conserve les identifiants d'API, fixtures originales et comportement du runtime.
## 0.1.5 — 2026-10-14

### Installation et publication

- Publie le package sur Packagist et simplifie son installation en une commande
  `composer require` dans un projet consommateur.
- Utilise les dépôts Composer par défaut et relie les métadonnées du package à
  sa documentation sur `https://pdftoolkit.github.io/fr/`.
- Verrouille les vérifications sur le core stable `roukmoute/pdf:0.5.3`, sans
  modifier les versions des outils de développement.
- Vérifie l'indexation des versions publiées et leur installation sans dépôts
  VCS ou locaux. Conserve l'API PHP, les mécanismes et les limites existants.

## 0.1.4 — 2026-10-14

### Documentation

- Relie la documentation commune à `https://pdftoolkit.github.io/fr/` et ajoute
  l'entrée stable du lecteur à `/modules/reader/`.
- Confie les guides communs et la publication du site à
  `PDFToolkit/pdftoolkit.github.io`, avec des sources de modules épinglées sur
  leurs versions publiées et leurs commits exacts.
- Corrige la compatibilité décrite dans le README pour refléter la contrainte
  existante `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5`.
- Conserve l'API, les mécanismes de lecture, les dépendances et leurs versions
  verrouillées ; cette version modifie uniquement la documentation.

## 0.1.3 — 2026-10-13

### Compatibilité

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5`.
- Verrouille les vérifications sur le core publié `0.4.0`, sous PHP 8.3, 8.4 et 8.5.
- Conserve l'API et les limites du lecteur ; aucun mécanisme de lecture supplémentaire
  n'est ajouté par ce correctif de compatibilité.

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
