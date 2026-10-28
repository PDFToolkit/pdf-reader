# Référence des composants

[English](../components.md) | Français

Le namespace racine est `PDF\Reading`. Les primitives telles que `DictionaryType`,
`ArrayType`, `NameType`, `IntegerType`, `StringType` et `ObjectIdentifier` viennent
du [modèle PDF partagé](https://github.com/PDFToolkit/pdf/tree/master/src/Syntax).

## Document et objets source

| Composant | Construction et rôle |
| --- | --- |
| `FileBytes` | `new FileBytes($bytes)` conserve les octets fournis explicitement. Aucun accès fichier ou réseau. |
| `DocumentReader` | `new DocumentReader($limits, $authentication)` ; `read(FileBytes): ReadDocument` ouvre le document structurel. Les limites ont une valeur par défaut explicite. |
| `ReadDocument` | Porte source, header, trailer effectif, index, budgets et caches. Construction habituelle par `DocumentReader`. |
| `ObjectResolver` | Interface `resolve(BasicType): BasicType`. Résout une référence dans un document sans parcourir tout le graphe. |
| `ObjectReference` | `new ObjectReference(ObjectIdentifier)` ; propriété `identifier`. La référence porte un identifiant, sans cible ni résolveur. Sa sérialisation est refusée. |
| `EncodedStream` | `dictionary()` expose la structure ; `encodedBytes()` est prêt pour les codecs et `originalEncodedBytes()` conserve le ciphertext source. Sa sérialisation est refusée. |

### Méthodes de `ReadDocument`

| Méthode | Résultat et comportement |
| --- | --- |
| `header(): FileHeader` | Version déclarée dans le header physique. Une éventuelle `/Version` du catalogue reste accessible dans son dictionnaire. |
| `trailer(): DictionaryType` | Trailer effectif, assemblé en donnant la priorité aux révisions récentes. Dans un document à flux xref, il contient les entrées de son dictionnaire. |
| `catalog(): DictionaryType` | Résout `/Root` ; contrôle `/Type /Catalog`, la référence `/Pages` et la version déclarée. |
| `object(ObjectIdentifier): BasicType` | Charge l'objet désigné ; retourne null pour un objet absent, libre, indisponible ou de génération différente. |
| `resolve(BasicType): BasicType` | Appelle `object()` pour une référence ; conserve une valeur directe inchangée. |
| `streamData(EncodedStream): string` | Décode le flux sous les budgets du document. Les accès suivants réutilisent le résultat. |

Ces opérations chargent le graphe de base. Les services de pages ci-dessous
ajoutent un parcours demandé explicitement ; le chargement du catalogue seul
ne visite pas cet arbre.

## Vues physiques

`DocumentReader::readRevisionHistory(FileBytes)` retourne un `ReadRevisionHistory`
comptable et itérable, d’anciens `ReadRevision` vers les nouveaux. Chaque vue
conserve `crossReference`, `boundary`, `rawDocument()` et `source(?int $byteLength)`.
`acceptsByteLength()` contrôle l’intervalle EOF sans choisir les octets signés.
`ReadDocument::crossReferences()` expose l’index effectif, dont `entries()` garde
aussi les entrées libres et indisponibles ; `objectSpan(ObjectIdentifier)` donne
l’étendue ordinaire ou null. Voir le [contrat physique](revision-history.md) pour
les bornes, suppléments hybrides, sections linéarisées regroupées, Contents, budgets et limites d’authentification.

## Pages, héritage et contenu

| Composant | Construction et rôle |
| --- | --- |
| `PageTreeReader` | `new PageTreeReader(ReadDocument, PageTreeLimits)` ; `read(): ReadPageTree` parcourt l'arbre complet dans l'ordre de `/Kids`. Contrôle les types, comptes, parents, cycles, identités répétées et plafonds. |
| `ReadPageTree` | Collection immuable comptable et itérable, produite après validation. Propriétés `document`, `root` et `pages` ; `page(int $index): ReadPage` commence à zéro et refuse un indice hors collection. |
| `ReadPage` | Vue immuable d'une feuille : propriétés `document`, `reference`, `dictionary` et `attributes`. Le dictionnaire source est conservé. |
| `PageAttributes` | Ressources effectives `DictionaryType`, MediaBox et CropBox `Rectangle`, rotation `IntegerType`. `normalizedRotation(): int` dérive une orientation 0/90/180/270, sans remplacer l'entier d'origine. |
| `PageInheritance` | Valeurs partielles d'un ancêtre, toutes facultatives. `effective(): PageAttributes` exige Resources/MediaBox et applique les valeurs par défaut uniquement à la feuille. |
| `PageAttributeReader` | Reçoit un `ObjectResolver`. `read(DictionaryType, PageInheritance): PageInheritance` résout les quatre entrées héritables et leurs types sans visiter le contenu des ressources. |
| `PageTreeTraversal` | État d'une opération de parcours : pile explicite, identités déjà visitées, ordre des feuilles et comptes des sous-arbres. |
| `PageTreeFrame` | Une branche en cours : référence, héritage, compte déclaré et enfants restant à visiter. Vérifie son compte une fois ses descendants visités. |
| `PageTreeLimits` | Plafonds strictement positifs : `maxDepth` 64, `maxNodes` 100 000 et `maxPages` 100 000 par défaut. |
| `PageContentsReader` | `new PageContentsReader(ReadDocument, PageContentLimits)` ; `read(ReadPage): PageContents` résout le contenu local d'une page du même document. |
| `PageContents` | Collection immuable comptable et itérable : propriétés `page` et `streams`, conservés dans l'ordre. Aucune concaténation ni décompression. |
| `PageContentLimits` | Plafond `maxStreams` strictement positif, 1 024 par page par défaut. |

Les quatre entrées héritables sont `Resources`, `MediaBox`, `CropBox` et
`Rotate`. Un Resources local remplace le dictionnaire hérité sans fusion.
Null est traité comme une absence. CropBox utilise MediaBox par défaut et
Rotate utilise zéro ; les autres attributs de page restent consultables dans
le dictionnaire source. Contents n'est jamais hérité. Absence, null et tableau
vide donnent une collection de contenu vide.

Les budgets de `ReadDocument` restent partagés pendant ces opérations.
Les plafonds d'arbre et de contenu sont propres à chaque demande de parcours
ou de lecture. Consulter le [guide des pages](pages.md) pour un exemple expliqué.

## Syntaxe et structures

| Composant | Rôle |
| --- | --- |
| `ByteCursor` | Vue bornée de bytes, construite avec un offset et une fin facultative. `position()`, `remaining()`, `peek()`, `read()`, `seek()`, `eof()` et `skipWhitespaceAndComments()` contrôlent son déplacement. |
| `SyntaxParser` | Reçoit un curseur, des limites et éventuellement un budget. `read()` lit une valeur PDF ; `unsignedInteger()`, `keyword()` et `atKeyword()` servent aux lecteurs structuraux. Ne résout pas les références. |
| `IndirectObjectReader` | Reçoit source, limites et budget. `read($offset, $expected, $resolver)` contrôle l'enveloppe d'un objet, son identifiant et l'étendue exacte d'un stream. |
| `CrossReferenceReader` | Reçoit source, limites, budget et décodeur. `read($offset)` produit une `CrossReference\Section` depuis une table ou un flux xref. |
| `ObjectStreamReader` | Reçoit limites et budget. `read($stream, $decodedBytes, $resolver)` analyse les membres d'un ObjStm dans leurs tranches respectives. |
| `DictionaryAccess` | Accès structurel typé à un dictionnaire, avec un `ObjectResolver` facultatif : `value()`, `integer()` et `hasName()`. |

### Namespace `PDF\Reading\CrossReference`

| Composant | Rôle |
| --- | --- |
| `EntryKind` | Enum distinguant les entrées `Free`, `Uncompressed`, `Compressed` et `Unavailable`. |
| `Entry` | Valeur portant numéro, type et deux champs propres au type. `generation()` donne la génération effective ; les membres comprimés ont génération zéro. |
| `Section` | Valeur d'une révision : offset, trailer, offset `/Prev` facultatif et liste d'entrées. |
| `Index` | Index sparse effectif, construit avec des sections de la plus récente à la plus ancienne. `entry($number)` retrouve l'entrée qui prévaut. |

L'index conserve les entrées libres récentes pour empêcher qu'un ancien objet
supprimé réapparaisse. Les types xref inconnus restent indisponibles et se
résolvent à null.

## Filtres et prédicteurs

`StreamDecoder::decode(EncodedStream, ReadBudget, ?ObjectResolver): string`
interprète la chaîne `/Filter` et ses `/DecodeParms`. Les noms de filtre complets
sont utilisés. Un filtre inconnu est refusé au moment du décodage, sans empêcher
la conservation de ses octets encodés.

Les flux de fichiers externes exigent un `ExternalStreamSource` explicite ; voir le [guide des flux externes](external-streams.md) pour FFilter, acquisition et limites cumulées.

### Namespace `PDF\Reading\Filters`

| Composant | Rôle |
| --- | --- |
| `Decoder` | Interface `decode(string, ReadBudget): string` des transformations. |
| `FlateDecoder` | Décompression zlib progressive en petites tranches, avec contrôle des données invalides, tronquées ou suivies d'octets supplémentaires. |
| `AsciiHexDecoder` | Décodage ASCIIHex : blancs PDF, chiffres hexadécimaux et dernier demi-octet éventuel. |
| `Ascii85Decoder` | Décodage ASCII85, blocs complets ou partiels et raccourci `z`. |
| `RunLengthDecoder` | Décodage des répétitions et segments littéraux RunLength. |
| `PredictorParameters` | Valeur `Predictor`, `Colors`, `BitsPerComponent`, `Columns`. Prédicteurs 1, 2 ou 10–15 ; composants de 1, 2, 4, 8 et 16 bits. |
| `PredictorDecoder` | Reconstruction des lignes TIFF ou PNG après Flate ou LZW. Les tags de ligne PNG désignent l'algorithme appliqué. |
| `DecodedBytes` | Accumulateur contrôlant la limite d'une sortie et le budget cumulé avant de retenir une tranche. |

DCTDecode, JPXDecode, CCITTFaxDecode et JBIG2Decode restent à implémenter.
Crypt est appliqué par le contexte du document authentifié, avant les codecs. La lecture structurelle d'un flux image peut donc réussir,
tandis que sa demande de décodage est refusée.

## Limites et diagnostics

| Composant | Rôle |
| --- | --- |
| `ReaderLimits` | Valeur immuable des plafonds, configurables par arguments nommés. Tous doivent être strictement positifs. |
| `ReadBudget` | Compteurs cumulatifs de valeurs analysées, entrées xref, octets conservés, octets décodés et tentatives d’acquisition externe, propres à une lecture. |
| `ParseException` | Erreur de structure ou de budget ; propriété `offset` facultative pour la position dans les bytes. |
| `UnsupportedPdfFeature` | Sous-type de `ParseException`, distinguant un mécanisme volontairement non pris en charge. |

Exemple de configuration :

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\ReaderLimits;

$reader = new DocumentReader(new ReaderLimits(
    maxFileBytes: 8 * 1024 * 1024,
    maxObjects: 10_000,
    maxCrossReferenceEntries: 20_000,
    maxDecodedStreamBytes: 16 * 1024 * 1024,
    maxTotalDecodedBytes: 32 * 1024 * 1024,
    maxRetainedBytes: 16 * 1024 * 1024,
));
```

Les appels de bas niveau peuvent recevoir un budget fourni explicitement.
Pour conserver les mêmes plafonds sur toute une lecture, utiliser le même
`ReaderLimits` et le même `ReadBudget` dans les composants composés.

[Architecture](architecture.md) · [Sécurité et valeurs par défaut](security.md)

## Authentification et octets conservés

`Security\StandardPasswordAuthentication` implémente `DocumentAuthentication`.
Le constructeur reçoit un `PdfDocEncodedPassword` pour R2–R4 ou un
`PreparedPassword` pour R5–R7. `ReadDocument::permissions()` expose le contrat
commun `DocumentPermissions::effectiveMask()`. Standard retourne le
`ReadPermissions` immuable : `mask`, `access`, `revision`, `effectiveMask()`.
`PublicKeyAuthentication` retourne `PublicKeyReadPermissions` avec faits de
destinataire par filtre et `subFilter` explicite, sans identité ni révision de mot de passe.
Voir [l’authentification par certificat](public-key-authentication.md).
`EncodedStream::originalEncodedBytes()` conserve les octets source ;
`encodedBytes()` fournit les octets déchiffrés prêts pour la décompression.
`Filters\LzwDecoder` reçoit la valeur `EarlyChange` du core ;
`LzwCodeReader` borne la lecture MSB de 9 à 12 bits.
Voir les [exemples et limites](authentication.md).

`Security\SourcePdfMacVerification` reçoit une limite CMS explicite et vérifie
la source avant déchiffrement. `SourceIntegrityPolicy` indique si une source non
protégée est acceptable ; `ReadDocument::mac()` expose le `PdfMacLocation`
vérifié sans décision de confiance dans une signature. `Security\PdfMacCoverage`
contrôle l’identité et l’étendue du véritable jeton source. `SourceRevision`
conserve le dernier trailer physique et les octets originaux ; `SourceStringSpans`
associe les chaînes créées par le parseur à leur identité, sous le budget du
document. Une chaîne issue d’un flux d’objets décodé ne peut pas se faire passer
pour une étendue du fichier source. Voir les [PDF MAC source](source-mac.md).

`StructuralPolicy::Pdf20` active les [contrôles EC3 ciblés](structural-policy.md). `Compatible` reste la valeur par défaut ; la politique est conservée par l’authentification, les vues physiques et la composition de la politique d’intégrité.
