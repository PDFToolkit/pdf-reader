# Référence des composants

Le namespace racine est `PDF\Reading`. Les primitives telles que `DictionaryType`,
`ArrayType`, `NameType`, `IntegerType`, `StringType` et `ObjectIdentifier` viennent
du [modèle PDF partagé](https://github.com/PDFToolkit/pdf/tree/master/src/Syntax).

## Document et objets source

| Composant | Construction et rôle |
| --- | --- |
| `FileBytes` | `new FileBytes($bytes)` conserve les octets fournis explicitement. Aucun accès fichier ou réseau. |
| `DocumentReader` | `new DocumentReader($limits)` ; `read(FileBytes): ReadDocument` ouvre le document structurel. Les limites ont une valeur par défaut explicite. |
| `ReadDocument` | Porte source, header, trailer effectif, index, budgets et caches. Construction habituelle par `DocumentReader`. |
| `ObjectResolver` | Interface `resolve(BasicType): BasicType`. Résout une référence dans un document sans parcourir tout le graphe. |
| `ObjectReference` | `new ObjectReference(ObjectIdentifier)` ; propriété `identifier`. La référence porte un identifiant, sans cible ni résolveur. Sa sérialisation est refusée. |
| `EncodedStream` | `new EncodedStream(DictionaryType, string)` ; `dictionary()` et `encodedBytes()` exposent la structure et les octets d'origine. Sa sérialisation est refusée. |

### Méthodes de `ReadDocument`

| Méthode | Résultat et comportement |
| --- | --- |
| `header(): FileHeader` | Version déclarée dans le header physique. Une éventuelle `/Version` du catalogue reste accessible dans son dictionnaire. |
| `trailer(): DictionaryType` | Trailer effectif, assemblé en donnant la priorité aux révisions récentes. Dans un document à flux xref, il contient les entrées de son dictionnaire. |
| `catalog(): DictionaryType` | Résout `/Root` ; contrôle `/Type /Catalog`, la référence `/Pages` et la version déclarée. |
| `object(ObjectIdentifier): BasicType` | Charge l'objet désigné ; retourne null pour un objet absent, libre, indisponible ou de génération différente. |
| `resolve(BasicType): BasicType` | Appelle `object()` pour une référence ; conserve une valeur directe inchangée. |
| `streamData(EncodedStream): string` | Décode le flux sous les budgets du document. Les accès suivants réutilisent le résultat. |

Ces opérations ne fournissent pas une vue aplatie des pages ou des ressources.
Leurs références sont parcourues explicitement avec les primitives du modèle.

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

### Namespace `PDF\Reading\Filters`

| Composant | Rôle |
| --- | --- |
| `Decoder` | Interface `decode(string, ReadBudget): string` des transformations. |
| `FlateDecoder` | Décompression zlib progressive en petites tranches, avec contrôle des données invalides, tronquées ou suivies d'octets supplémentaires. |
| `AsciiHexDecoder` | Décodage ASCIIHex : blancs PDF, chiffres hexadécimaux et dernier demi-octet éventuel. |
| `Ascii85Decoder` | Décodage ASCII85, blocs complets ou partiels et raccourci `z`. |
| `RunLengthDecoder` | Décodage des répétitions et segments littéraux RunLength. |
| `PredictorParameters` | Valeur `Predictor`, `Colors`, `BitsPerComponent`, `Columns`. Prédicteurs 1, 2 ou 10–15 ; les prédicteurs actifs requièrent des composants de 8 bits. |
| `PredictorDecoder` | Reconstruction des lignes TIFF ou PNG après Flate. Les tags de ligne PNG désignent l'algorithme appliqué. |
| `DecodedBytes` | Accumulateur contrôlant la limite d'une sortie et le budget cumulé avant de retenir une tranche. |

DCTDecode, JPXDecode, CCITTFaxDecode, JBIG2Decode, LZWDecode et Crypt ne sont
pas implémentés. La lecture structurelle d'un flux image peut donc réussir,
tandis que sa demande de décodage est refusée.

## Limites et diagnostics

| Composant | Rôle |
| --- | --- |
| `ReaderLimits` | Valeur immuable des plafonds, configurables par arguments nommés. Tous doivent être strictement positifs. |
| `ReadBudget` | Compteurs cumulatifs de valeurs analysées, entrées xref et octets décodés, propres à une lecture. |
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
