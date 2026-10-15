# Fixtures indépendantes de PDF MAC source

[English](README.md) | Français

Les quinze PDF sont des tests synthétiques originaux, indépendants du writer
PDFToolkit. `manifest.json` fixe leur SHA-256 et l’emplacement MAC attendu.
Les mots de passe de test publics sont `user` et `owner` ; les clés de signature
temporaires ont été supprimées. Les certificats n’établissent aucune confiance
PKI ou dans un horodatage.

- pyHanko 0.37.0 crée quatorze PDF : six condensats, références croisées en table
  et flux, un objet compressé, une révision incrémentale, des signatures et un
  horodatage documentaire, avec chiffrement R6 et R7.
- Le fichier R5 conserve les identifiants de la fixture qpdf R5 indépendante
  existante. pypdf récupère sa clé de test publique ; cryptography écrit les
  payloads CBC R5 et les permissions chiffrées exigeant le MAC. pyHanko produit
  le jeton CMS autonome sans modification de ses algorithmes de sécurité.
- `tools/validate_source_mac.py` contrôle CMS, les condensats exacts du document
  et de la signature, HKDF, AESKW et HMAC avec pyHanko/cryptography. qpdf 12.4.2
  contrôle les treize fichiers non R7 sans avertissement. R7 utilise les
  implémentations indépendantes pyHanko/cryptography inchangées ; qpdf ne gère
  pas AESV4.

Depuis un checkout, générer et vérifier un nouveau corpus :

```console
python -m pip install --require-hashes -r tools/source-mac-validation-requirements.txt
python tools/validate_source_mac.py --php php --qpdf qpdf
```

`--work-dir /repertoire/de/sortie/explicite` conserve les PDF et le manifeste
générés. Les clés, IV et numéros de série sont renouvelés : la régénération
vérifie le même comportement sans reproduire les SHA-256 conservés.
L’outil produit aussi douze variantes malveillantes, refusées par le validateur
indépendant et le lecteur natif. Plusieurs gardent un HMAC correctement calculé
en violant la couverture source, les cardinalités CMS ou les assertions
d’intégrité. Les tests natifs contrôlent le corpus conservé et modifient chaque
source avant sa lecture.

Le [contrat MAC français](../../../docs/fr/source-mac.md) précise le périmètre,
les limites de ressources et les références normatives.
