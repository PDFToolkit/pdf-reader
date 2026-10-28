# Import explicite vers le writer

[English](../import.md) | Français

Le Reader 0.7 fournit `PDF\Reading\Import\ImportSession` pour copier un graphe source sélectionné vers les objets natifs du writer. Le core 0.11 ou supérieur est requis. Cette opération travaille en mémoire : le chargement, les décisions de permission, les chemins de sortie et la version PDF cible appartiennent à l'application.

```php
use PDF\FileStructure;
use PDF\Reading\Import\ImportLimits;
use PDF\Reading\Import\ImportSession;
use PDF\Reading\Import\StreamImportMode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\IndirectReference;

// $document est un ReadDocument lu/authentifié explicitement.
$sourceRoot = $document->trailer()->get('Root');
if ($sourceRoot === null) {
    throw new LogicException('Missing source catalog.');
}
$graph = (new ImportSession(
    $document,
    StreamImportMode::PreserveEncoded,
    new ImportLimits(maxObjects: 10000, maxBytes: 33554432),
))->import($sourceRoot);
$root = $graph->root();
if (!$root instanceof IndirectReference) {
    throw new LogicException('Expected a native catalog reference.');
}
// Choisir explicitement la version cible ; le writer conserve ses validations.
$bytes = (new FileStructure(new FileHeader('2.0'), new FileBody(), $root))->toPdf();
```

L'[exemple CLI exécutable](../../examples/import.php) reçoit un chemin d'entrée, un nouveau chemin de sortie distinct et la version explicite `1.7` ou `2.0`. Il n'écrase ni sa source ni une sortie existante.

## Identité et atomicité

Une session appartient à un `ReadDocument`. Passer toutes les racines devant partager leurs identités dans le même appel `import($first, $second, ...)` ; utiliser `ImportedGraph::$roots` pour plusieurs résultats. `root()` exige un seul résultat. `reference($sourceIdentifier)` retrouve la référence native correspondante dans le seul document source de ce graphe.

L'import résout les alias, distingue numéro et génération, puis attribue de nouvelles références natives automatiques. Deux documents aux mêmes numéros restent distincts grâce à leurs sessions séparées. Les objets absents, libres ou de génération indisponible conservent le comportement PDF-null du lecteur. Dictionnaires, tableaux et flux sont alloués avant le parcours et initialisés une seule fois ; les cycles `/Parent` des pages, cycles de tableaux et cycles dans les dictionnaires de flux conservent donc leurs identités. Les chaînes de références sans objet terminal sont rejetées.

Une session s'utilise une fois, même après échec. Aucun résultat incomplet n'est retourné ni conservé pour une reprise. `ObjectReference` et `EncodedStream` restent des vues immuables dont la sérialisation lève une exception. Le pont crée des valeurs natives sans appeler ces méthodes de sérialisation.

Tous les objets accessibles sont copiés. Le pont ne supprime pas `/Parent` ni les liens retour d'annotations, ne fusionne pas les arbres de noms, ne réécrit pas les références incorporées aux octets de contenu et ne répare pas la sémantique source. Sélectionner une page peut donc atteindre tout son arbre. Choisir explicitement le sous-graphe adapté ; le writer valide le graphe qu'il émet réellement.

## Politique des flux

`StreamImportMode` est un argument obligatoire :

| Mode | Résultat |
| --- | --- |
| `PreserveEncoded` | Conserve `encodedBytes()` et le graphe ordonné des filtres/paramètres. Aucun codec n'est exécuté pour un flux local ordinaire ; un codec image opaque peut rester encodé. |
| `Decode` | Décode avec les codecs bornés du lecteur et émet un flux natif sans filtre. Un codec absent provoque un échec explicite. |

Les métadonnées XML (`/Type /Metadata` ou `/Subtype /XML`) utilisent toujours les octets source décodés, même en mode préservation. Une source PDF 2.0 effective, y compris via la version du catalogue, est contrôlée par `XmpUtf8Encoding`. Les métadonnées PDF 1.7 ne sont pas transcodées silencieusement : le writer vérifie leur encodage si l'application passe à PDF 2.0. Un flux encodé ne peut pas se faire passer pour une preuve d'émission UTF-8 décodée.

Les flux authentifiés fournissent leurs octets encodés **déchiffrés**, jamais le ciphertext de `originalEncodedBytes()`. Les originaux restent inchangés. L'authentification retire le chiffrement source ; un `/Crypt /Identity` initial restant est validé puis supprimé. Tout autre Crypt restant est rejeté. Le chiffrement du nouveau writer demeure une opération explicite séparée.

`/Length`, `/Filter` et `/DecodeParms` sont reconstruits par le flux natif. `/DL` reste une indication source. Les références de paramètres comme `JBIG2Globals` sont remappées. Les flux de transport `/ObjStm` et `/XRef` sont refusés, car leurs offsets et numéros internes ne peuvent pas être copiés tels quels ; leurs objets logiques résolus peuvent être importés.

Les flux externes `/F` sont refusés sans `ExternalStreamSource` explicitement fourni à la session d'import, indépendamment de la capacité du lecteur source. Le pont acquiert les données une fois, applique `FFilter`/`FDecodeParms` puis incorpore le résultat selon le mode choisi. La bibliothèque n'ouvre aucun chemin ni URL et ne conserve pas la localisation externe sur le nouveau flux.

## Limites et autorité de sécurité

`ImportLimits` borne les objets source, valeurs visitées, profondeur, octets du graphe conservé, octets par flux, filtres, arêtes cycliques et requêtes externes. `maxCycles: 0` refuse les cycles. Les limites se cumulent sur toutes les racines, alias et références répétées compris. La limite d'octets borne aussi le travail de décodage cumulé par un compteur séparé ; les allocations intermédiaires conservent les contrats bornés des décodeurs. Les limites de lecture du document source restent actives.

Le pont refuse les dictionnaires de chiffrement source, les entrées `Encrypt`/`AuthCode` non nulles, les dictionnaires de signature/horodatage, les champs de signature signés et les dictionnaires identifiés par Contents avec ByteRange ou SubFilter. Il ne retire pas silencieusement une signature en prétendant conserver un document signé. Un sous-graphe de contenu non signé peut être sélectionné dans une source signée ; l'application décide si cette réutilisation est autorisée.

Aucune `SourceRevision` ne devient une `FileRevision` native ; aucune autorisation de modifier une signature, aucun résultat d'authentification source ni aucune confiance PKI ne sont transférés. La copie n'est pas une modification incrémentale de la source. Les permissions lues restent disponibles pour la politique applicative et ne valent pas automatiquement autorisation d'écrire ou de distribuer un nouveau PDF.

## Preuves et périmètre source

Le contrat suit ISO 32000-2:2020, clauses 7.3.8–7.3.10 (pages physiques 46–49), 7.4.1 (49–50), 7.6 (chiffrement source), et EC3 #296 (730, UTF-8 des métadonnées). [Le registre](../../resources/import.json) relie ce périmètre à l'implémentation et aux validations ; il ne déclare pas une conformité sémantique PDF complète.

`ReadingImportTest` et `ReadingImportStreamsTest` couvrent identités, cycles, générations, sources multiples, échec atomique, limites cumulées, préservation du ciphertext authentifié, frontières des capacités externes et changement de version des métadonnées. `tools/validate_import.py` contrôle indépendamment les deux modes sur PDF classiques et à objets compressés, sources qpdf RC4/AES à mot de passe et source pyHanko chiffrée par certificat. pypdf vérifie contenu, octets encodés et liens ; qpdf contrôle chaque PDF produit.

[Architecture](architecture.md) · [Authentification](authentication.md) · [Sécurité](security.md)
