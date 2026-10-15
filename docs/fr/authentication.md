# Lire des PDF protégés par mot de passe

[English](../authentication.md) | Français

L’authentification est une capacité explicite d’un `DocumentReader`. Sans elle,
un trailer chiffré effectif lève `EncryptedPdfException` avant l’interprétation
du catalogue. La bibliothèque ne recherche aucun mot de passe et n’ouvre aucun
fichier externe.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;

// L’application fournit les octets et prépare le mot de passe UTF-8.
$authentication = new StandardPasswordAuthentication(new PreparedPassword($password));
$reader = new DocumentReader(authentication: $authentication);
$document = $reader->read(new FileBytes($bytes));
$permissions = $document->permissions();
```

`PreparedPassword` représente une entrée UTF-8 déjà préparée pour R5–R7 ; le
choix de la préparation Unicode est une étape explicite de l’application.
Pour R2–R4, utiliser plutôt
`new PdfDocEncodedPassword(new HexadecimalString($pdfDocEncodedBytes))`, avec
`PDF\Security\PdfDocEncodedPassword` et
`PDF\Syntax\Object\StringObject\HexadecimalString`. Ces octets ne sont pas UTF-8.
Les backends AES par défaut demandent OpenSSL ; d’autres backends AES du core
peuvent être injectés au constructeur. Aucun exécutable n’est lancé à l’exécution.

| Mécanisme source | Traitement natif |
| --- | --- |
| R2 / V1 | RC4, clé de fichier sur 40 bits. |
| R3 / V2 | RC4, longueur de clé historique variable. |
| R4 / V4 | Filtres nommés None, V2, AESV2 ; choix pour chaînes, flux et fichiers incorporés. |
| R5 / V5 | Authentification historique AES-256 et permissions chiffrées. |
| R6 / V5 | Hash moderne du mot de passe, AESV3 et permissions authentifiées. |
| R7 / V6 | Payloads AESV4 GCM authentifiés ; un tag modifié est refusé. |

`ReadPermissions` conserve le `mask` source, le type `access` (User ou Owner)
et la `revision`. `effectiveMask()` retourne le masque source pour l’utilisateur
et toutes les permissions du document pour le propriétaire. L’application choisit
la présentation et l’application des droits. Ce résultat ne valide aucune
signature numérique. `AuthenticationFailed` identifie un mot de passe incorrect
sans l’inclure dans le message. Les dictionnaires ou données chiffrées mal formés
lèvent `ParseException`.

Le document authentifié déchiffre une seule fois les chaînes et flux, avec la clé
de l’objet propriétaire lorsqu’elle est requise. Un premier filtre `Crypt`
explicite est consommé avant les autres codecs. Les flux xref, le dictionnaire
de chiffrement et les `Contents` des signatures sont exemptés. Les membres d’un
flux d’objets sont analysés après déchiffrement du conteneur, sans second
déchiffrement. Les métadonnées en clair et le filtre des fichiers incorporés
suivent les réglages de leur dictionnaire.

### Propriétaire des métadonnées et types de flux indirects

La version 0.3.1 lie `EncryptMetadata=false` à la référence `Metadata` réelle du
catalogue effectif. Le flux `/Type /Metadata` d'une page ou d'un Form reste chiffré
sauf sélection explicite d'Identity. Les noms `Type` indirects sont résolus avant
le choix d'`EFF` plutôt que `StmF`. Les catalogues en flux d'objets chiffrés gardent
ces règles sans chargement récursif de leurs métadonnées pendant leur déchiffrement.

L'authentification native fournit la référence immuable de racine. Un
`DocumentAuthentication` personnalisé qui construit `DocumentDecryption` peut
aussi fournir ce dernier argument optionnel `documentRoot` ; sans lui, aucune
exemption implicite des métadonnées du catalogue n'est devinée. `Crypt /Identity`
explicite reste disponible indépendamment. Source : ISO 32000-2:2020, §7.3.10
(page physique 49), `EncryptMetadata` (page 94) et métadonnées de page (page 121).

La table 20 (page physique 88) fixe la clé V4 à 128 bits ; Length global et son
défaut 40 concernent V2/V3. Une entrée CF portant un nom standard comme Identity
est ignorée avant l'interprétation de sa valeur et ne redéfinit pas ce filtre.
Son comportement d'identité reste disponible.

`EncodedStream::encodedBytes()` expose les octets déchiffrés prêts pour les codecs ;
`originalEncodedBytes()` conserve le ciphertext source. Le plaintext conservé
consomme le budget commun du document. L’import et l’écriture nécessitent encore
une couche explicite de réattribution des références ; les objets source
authentifiés ne peuvent pas être sérialisés directement dans un nouveau document.

La vérification des PDF MAC source (`KDFSalt`/`AuthCode`) et l’authentification par
clé publique ne sont pas implémentées par cette capacité. Ces fichiers sont
refusés avant l’interprétation de leur contenu protégé. Les codecs image non pris
en charge restent distincts du déchiffrement. Une authentification réussie ne
certifie pas que le PDF original peut être exécuté sans risque par un autre logiciel.

La validation indépendante utilise 24 PDF R2–R7 réels : qpdf produit R2–R6 et
Python cryptography produit indépendamment les payloads AESV4 à partir des entrées
de mot de passe R6 de qpdf. Les deux mots de passe, chaînes binaires, flux d’objets,
réglages des métadonnées et exceptions des signatures sont vérifiés. Ces
credentials synthétiques sont publics. Sept cas supplémentaires vérifient les
métadonnées catalogue/page/Form, Identity explicite et les types indirects des
fichiers embarqués, la clé V4 sans Length global et les entrées CF standard ignorées,
avec catalogues classiques ou compressés. qpdf déchiffre les
fichiers de métadonnées indépendamment ; cryptography construit les payloads EFF
avec les credentials de qpdf. qpdf 12.4.2 ne déchiffre pas EFF lorsque StmF vaut
Identity, même avec un Type direct ; son déchiffrement n'est donc pas un oracle
pour ce choix précis. Ses algorithmes ne sont jamais modifiés pour le test.
qpdf 12.4.2 exige aussi Length global pour V4 ; le cas sans cette clé conserve
tous les offsets et octets chiffrés de la source vérifiée indépendamment et suit
le contrat fixe de 128 bits de la table 20. Voir
les [contrôles de sécurité](security.md) et les [composants](components.md).
