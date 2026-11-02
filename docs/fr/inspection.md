# Inspecter la structure PDF

[English](../inspection.md) | Français

Reader 0.8 expose un instantané immuable du **PDF source analysé**. Il ne dérive
pas de HTML, ne remplace pas le contenu par les fichiers HTML/MathML associés, ne
modifie pas le PDF, ne valide pas sa conformité d’accessibilité et ne certifie pas
les signatures. Un visualiseur peut ainsi montrer les balises effectivement
présentes même lorsque le rendu emploie un contenu de remplacement.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Inspection\DocumentInspector;
use PDF\Reading\Inspection\InspectionLimits;

$document = (new DocumentReader())->read(new FileBytes($pdfBytes));
$inspection = (new DocumentInspector(new InspectionLimits(
    maxDepth: 48,
    maxNodes: 5000,
    maxPages: 100,
    maxObjects: 2000,
    maxValues: 20000,
    maxBytes: 2097152,
    maxStringBytes: 4096,
    maxAssociations: 1000,
    maxDiagnostics: 100,
)))->inspect($document);
$json = json_encode($inspection, JSON_THROW_ON_ERROR);
```

L’application fournit les octets, authentifie les PDF chiffrés avec les API
existantes et applique `ReadDocument::permissions()`. L’inspection n’accorde aucun
accès et ne contourne pas l’authentification. `authenticatedPermissions` contient
le masque effectif vérifié, ou `null` pour un document non chiffré ; ce n’est pas un
résultat de signature. Afficher toute chaîne provenant du PDF comme du texte,
jamais comme du balisage exécutable ni comme une URL à ouvrir automatiquement.

## Contrat de l’instantané (schemaVersion 1)

`DocumentInspection` est `readonly` et implémente `JsonSerializable`. Ses propriétés
publiques correspondent aux membres JSON suivants. Les listes conservent l’ordre source.

| Membre | Forme |
| --- | --- |
| `document` | `version`, `tagged`, `catalogReference`, `catalog`, `structureRootReference`, `structureRoot`, `authenticatedPermissions` |
| `pages` | Liste de `{number, reference, properties}` ; numéros de page à partir de 1 |
| `roots` | Identifiants ordonnés des nœuds racines |
| `nodes` | Liste plate et ordonnée de nœuds décrits ci-dessous |
| `objects` | `{id, reference, status, value}` pour le développement borné des références |
| `roleMappings` | `{owner, namespace, source, target}` conservant les déclarations d’origine |
| `associatedFiles` | `{id, owner, reference, filename, relationship, properties, embeddedStreams}` |
| `diagnostics` | `{code, message, nodeId, reference}` ; contexte nullable et messages fixes |
| `truncated` | Une limite a empêché l’inspection complète, y compris pour les pages ou diagnostics |

Une référence est `{"number":"12","generation":0}`. Les numéros d’objet et valeurs
entières sont des **chaînes** décimales, préservant les valeurs au-delà de la
précision entière de JavaScript. `catalog`, `structureRoot`, les propriétés des
pages/nœuds et les valeurs d’objets utilisent la représentation typée ci-dessous.
Une racine absente vaut `null` ; `tagged:false` est un PDF inspectable, sans erreur
de dérivation HTML.

Un nœud contient `id`, `parentId`, `kind`, `reference`, `role`, `namespace`, `page`,
`mcid`, `objectReference`, `properties` et `children`. Les identifiants tels que
`node-1` sont propres à l’instantané. `kind` vaut `element`, `mcid`, `mcr`, `objr`,
`invalid`, `cycle` ou `repeated` ; `children` contient les identifiants ordonnés.
`role` est un nom typé. `namespace` vaut `{identity,uri,reference}` ou `null` ; deux
objets d’espace de noms partageant une URI conservent des identités distinctes.
`page` vaut `{number,reference,inherited}` ou `null`. Une page non résolue a
`number:null`. L’héritage ne concerne que ce champ d’affichage : aucun `/Pg` n’est
ajouté aux propriétés d’origine. `mcid` est une chaîne décimale ou `null`.
`objectReference` préserve la référence `/Obj` originale d’un OBJR.

Le `owner` d’une correspondance vaut `structure-root` ou l’identité d’un espace
de noms. `source` est un nom typé et `target` la valeur originale typée, y compris
les tableaux désignant un espace de noms. Les chaînes de correspondances ne sont
pas interprétées comme des rôles HTML. L’absence de RoleMap pour des balises
standard est normale. Les dictionnaires originaux exposent aussi ces données.

Le `owner` d’un fichier associé est `{kind,id,reference}`, avec kind égal à
`catalog`, `page`, `structure` ou `embedded-files`. Un fichier peut légitimement
avoir plusieurs propriétaires. `filename` et `relationship` sont des libellés
d’affichage optionnels ; leurs octets d’origine restent dans `properties`. Chaque
flux embarqué contient `{key,reference,properties,encodedByteLength}`, avec une
clé typée. Aucun contenu n’est décodé, téléchargé ou exécuté. Les `/AF` du catalogue,
des pages et éléments ainsi que `/Names/EmbeddedFiles` sont listés ; les références
arbitraires vers d’autres pièces jointes restent visibles dans le graphe d’objets.

### Valeurs typées

| `type` | Champs supplémentaires |
| --- | --- |
| `null`, `boolean`, `integer`, `real` | `value` (null, booléen, chaîne décimale ou nombre analysé) |
| `name` | `hex`, `byteLength`, `preview`, `truncated` |
| `string` | `hex`, `byteLength`, `preview`, `truncated`, `syntax` (`literal` ou `hexadecimal`) |
| `reference` | `reference`, `target`, `status` (`queued` ou `omitted`) |
| `array` | `items`, `truncated` |
| `dictionary` | `entries:[{key,value}]`, `truncated` ; clés de type name |
| `stream` | `dictionary`, `encodedByteLength` ; aucun contenu |
| `truncated` | `reason` |
| `unsupported` | Aucun champ supplémentaire |

`hex` conserve les octets analysés des noms/chaînes. En cas de troncature, il s’agit
explicitement d’un préfixe et `byteLength` garde la longueur d’origine. L’aperçu des
noms emploie la notation PDF `/Name#XX` sans deviner d’encodage. L’aperçu des chaînes
interprète l’encodage texte PDF selon sa version effective ; c’est une commodité,
car une chaîne d’octets PDF n’est pas nécessairement du texte. Un encodage invalide,
une chaîne tronquée ou l’indisponibilité d’UTF16 donne un aperçu null ; les octets
restent accessibles. Un aperçu peut contenir des contrôles : l’interface doit les
afficher de façon sûre.

Par exemple, l’entrée originale `/A 12 0 R` reste une référence :

```json
{"key":{"type":"name","hex":"41","byteLength":1,"preview":"/A","truncated":false},"value":{"type":"reference","reference":{"number":"12","generation":0},"target":"obj-12-0","status":"queued"}}
```

Chercher `obj-12-0` dans `objects` pour inspecter sa valeur développée séparément.
Le statut de cette ligne vaut `available`, `missing`, `failed` ou `omitted`. Les
références absentes/libres/de mauvaise génération sont distinctes d’un véritable
objet null. Une référence omise par le budget de file d’attente a
`target:null,status:"omitted"` : ne pas supposer que chaque référence a sa ligne.
Les chaînes physiques de références gardent une ligne par objet. Les identifiants
d’objet sont propres au document ; les identités directes comme `direct-1` sont
propres à l’instantané.

« Original » signifie valeurs PDF analysées avant dérivation du contenu. Ce n’est
pas la syntaxe lexicale octet pour octet : espaces, échappements alternatifs,
écriture des nombres et disposition du dictionnaire ne sont pas reconstruits.
Les réels gardent la précision numérique du parseur. Le chiffrement a déjà été
authentifié/déchiffré par Reader.

## Limites et diagnostics

Les valeurs par défaut d’`InspectionLimits` sont : profondeur 48, 5000 nœuds,
5000 pages, 2000 objets, 50000 valeurs, 2097152 octets comptabilisés, 2048 octets
par chaîne/nom, 2000 associations et 100 diagnostics. Elles doivent être positives.
Les budgets sont cumulés entre propriétés, libellés de correspondance, métadonnées
de fichiers et objets développés, y compris les présentations répétées d’un même
dictionnaire. Le budget d’octets comptabilise prudemment les données variables et
le coût des enregistrements ; il ne plafonne pas le tas PHP, les espaces du JSON
indenté ni l’enveloppe fixe du résultat. L’application peut également plafonner la
taille finale du JSON. `ReaderLimits` continue de borner l’analyse séparément.

Le parcours suit `/K`, jamais les liens parents. Les cycles, éléments répétés et
branches malformées restent visibles avec diagnostics ; les autres branches
restent inspectables. L’arbre des pages est validé séparément. S’il ne peut pas
l’être, la liste des pages est vide, les propriétés de structure disponibles
restent visibles et `truncated` vaut true. Les limites produisent un instantané
partiel explicite. La structure est inspectée avant le développement général des
objets afin que de grandes ressources ne prennent pas priorité sur les balises.
Il s’agit d’un outil d’inspection, pas d’un validateur sémantique exhaustif : les
propriétés sources font foi même lorsqu’elles sont malformées.

L’inspecteur ne décode ni contenu de page, ni image, ni pièce jointe. Reader peut
toutefois décompresser les flux d’objets nécessaires à la lecture des dictionnaires,
sous ses limites existantes. L’ouverture, l’authentification et les erreurs
irrécupérables du parseur peuvent encore lever une exception avant l’inspection.
Les résultats ne sont pas partagés entre documents et une nouvelle inspection
ne modifie pas le graphe source.

[Composants](components.md) · [Sécurité](security.md) · [Pages](pages.md)
