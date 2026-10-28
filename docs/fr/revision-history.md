# Historique physique des révisions

[English](../revision-history.md) | Français

Le Reader expose les véritables préfixes sources d’un PDF incrémental
chronologique. Ces vues physiques immuables conservent les octets d’origine,
les sections xref et les identités d’objets effectives. Elles n’établissent ni
confiance dans une signature, ni autorité DocMDP/FieldMDP, ni permission de modifier.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

// L’application fournit ces octets et borne leur acquisition.
$history = (new DocumentReader())->readRevisionHistory(new FileBytes($bytes));
foreach ($history as $revision) {
    $raw = $revision->rawDocument();
    $exactPrefix = $revision->source();
    $xrefOffset = $revision->crossReference->section->offset->value;
    $physicalTrailer = $revision->crossReference->section->trailer;
    $effectiveTrailer = $raw->trailer();
    $effectiveEntries = $raw->crossReferences()->entries();
}
```

`ReadRevisionHistory` est comptable et itérable, de l’ancienne révision à la plus
récente, avec des ordinaux commençant à zéro et `latest()`. Sa propriété `source`
conserve tous les octets fournis. Chaque `ReadDocument` brut résout les objets
uniquement avec l’index effectif de son préfixe : les remplacements ultérieurs
ne contaminent pas les anciens graphes ; entrées libres et générations réutilisées
gardent leur sémantique. `PhysicalCrossReference` conserve la section, sa fin
lexicale `endOffset`, le booléen `stream` et l’éventuel supplément hybride
`auxiliary`. La table porte `/Prev` ; les entrées du supplément prévalent.

Un fichier initial linéarisé possède deux sections xref, mais une seule révision
physique complète. Sa section de première page porte `crossReference` ; `linearized`
conserve la section principale, l’éventuel footer factice de première page,
l’étendue source du dictionnaire de linéarisation et sa longueur déclarée `L`.
`physicalParts()` retourne les corps xref exacts, suppléments hybrides compris.
Un `L` valide fixe la longueur du préfixe initial dans son véritable intervalle EOF,
même si les mises à jour ajoutent des blancs. Ce regroupement physique n’interprète
pas les flux de hints et ne certifie pas une distribution réseau optimisée.

## EOF exact et longueurs signées

Chaque `RevisionBoundary` conserve cinq offsets absolus :

| Propriété | Sens |
| --- | --- |
| `footerOffset` | Premier octet du véritable mot-clé `startxref`, après les éventuels objets indexés finaux. |
| `eofOffset` | Premier `%` du marqueur `%%EOF` vérifié. |
| `eofMarkerEnd` | Premier octet après ses cinq caractères ; longueur minimale admissible. |
| `endOffset` | Fin conventionnelle du préfixe, incluant la première fin de ligne du marqueur si présente ; une révision initiale linéarisée utilise son `L` vérifié. |
| `maximumEndOffset` | Premier octet suivant qui n’est pas un blanc, ou longueur totale de la source. |

`acceptsByteLength($length)` contrôle l’intervalle inclusif entre `eofMarkerEnd`
et `maximumEndOffset`. Cet intervalle ne contient que des blancs PDF. `source()`
retourne le préfixe conventionnel ; `source($length)` retourne exactement ce
préfixe admissible, sans retirer, ajouter ni normaliser d’octet. Une longueur
hors intervalle lève `ParseException`.

Un vérificateur de signature doit choisir la longueur du véritable `ByteRange`,
lier cette source exacte et valider séparément les plages, Contents et CMS.
Correspondre à un intervalle EOF n’établit aucun de ces faits. Cette opération
ne choisit pas les octets signés et ne crée pas de `FileRevision` du writer.

## Objets bruts et authentification

`ReadDocument::crossReferences()->entries()` expose les entrées effectives,
y compris libres et indisponibles. `objectSpan($identifier)` donne le
`SourceObjectSpan` d’un objet ordinaire, de son véritable header jusqu’à `endobj`.
Il retourne null pour les membres compressés, objets absents/libres et mauvaises
générations. Les membres compressés ont des positions dans des conteneurs décodés,
sans étendue indépendante dans le fichier source.

`rawDocument()->sourceRevision()->span($string)` expose la provenance du jeton
chaîne établie par le parseur, délimiteurs compris pour les `Contents` d’une
signature. Une chaîne arbitraire de même valeur n’acquiert pas cette provenance.
Les Contents de signature gardent aussi leur étendue d’origine après une lecture
authentifiée séparée. Une chaîne ordinaire déchiffrée n’atteste pas la présence
d’un jeton en clair dans la source.

L’inspection physique n’authentifie rien et n’applique pas la politique d’intégrité
source. Les chaînes et flux bruts peuvent être chiffrés ; `permissions()` et
`mac()` restent null. Ce graphe ne constitue pas du contenu authentifié. Pour
interpréter un préfixe choisi, appeler explicitement un
`DocumentReader::read($revision->source($exactLength))` configuré séparément avec
le mot de passe ou la capacité de destinataire et la politique d’intégrité requis.
Ses contrôles normaux d’authentification et de MAC s’appliquent. Les flux d’objets
chiffrés ne sont décodés que dans ce graphe authentifié séparé. L’inspection ne
peut pas résoudre une longueur structurelle de flux nécessitant elle-même le
décodage d’un flux d’objets chiffré.

## Bornes et organisations physiques prises en charge

Toutes les vues d’un historique partagent un `ReadBudget` : valeurs analysées,
entrées xref, données décodées et octets conservés se cumulent entre révisions.
Chaque préfixe, index effectif et étendue d’objet ordinaire conservé est compté,
ainsi que les copies supplémentaires de préfixes exacts. Le préfixe conventionnel
en cache n’est pas recopié. `ReaderLimits::maxRevisions` et les limites existantes
d’analyse/décompression s’appliquent. Une lecture authentifiée `read()` reste une
opération séparée avec son budget ; l’application borne le nombre de ces appels.

L’inspection contrôle les étendues physiques de tous les objets ordinaires
effectifs, versions historiques comprises, avant de retourner l’historique.
Elles ne peuvent chevaucher d’autres objets, les corps xref ou les footers.
Les octets entre étendues connues doivent être des blancs ou commentaires ;
un faux header d’objet dans une chaîne, un flux ou un commentaire ne peut
revendiquer un objet indépendant. Une ancienne révision ne peut adresser des
octets futurs. La syntaxe non indexée et un footer ajouté pointant sur un ancien
xref sont refusés.

L’organisation prise en charge est une chaîne `/Prev` chronologique de tables,
flux ou sections hybrides. Des objets ordinaires indexés peuvent suivre le flux
xref principal avant son footer ; leurs étendues réelles, y compris les longueurs
indirectes de flux, sont vérifiées avant de localiser `startxref`. La paire initiale
linéarisée est reconnue à partir de son véritable premier objet et de ses paramètres
directs bornés. Son `/Prev` vers l’avant doit désigner exactement la section
principale, sans autre `/Prev` ; son footer final désigne la section de première
page. Chaînes arbitraires progressant vers l’avant, objets non indexés et commentaires dans le
footer restent hors de cette API d’inspection. Certaines
de ces organisations sont des PDF valides ; `read()` conserve son comportement.
Ce service fournit une inspection structurelle bornée, pas une validation générale
de conformité ni une réparation.

## Validation indépendante

`tools/validate_revision_history.py` produit des historiques table, stream et
hybrides, en clair et chiffrés AES-256, avec les API pyHanko non modifiées. Chacun
contient une révision initiale, une véritable signature d’approbation CMS et une
mise à jour ultérieure d’objet. La source hybride initiale a une vraie table
associée et `/XRefStm` ; sa signature est créée ensuite. Une organisation initiale
assemblée séparément ajoute des objets indexés après le flux xref, dont un flux
de longueur indirecte ; pyHanko non modifié signe et met aussi à jour cette source.
Quatre fichiers initiaux sont linéarisés indépendamment par qpdf (tables/flux,
clair/chiffré), puis signés et mis à jour par pyHanko. PyHanko compte séparément
leurs deux sections xref initiales ; l’oracle les associe explicitement à une seule
révision physique complète. Cinq fixtures pypdf/qpdf existantes ajoutent suppression et réutilisation de
génération. Les 42 préfixes des 16 sources passent qpdf sans récupération. PyHanko
contrôle indépendamment les offsets xref des révisions, la résolution d’objets et 22 vérifications
mathématiques de signatures. La validation est hors ligne sans racine de
confiance ; la confiance dans les certificats et l’autorisation des modifications
ultérieures ne sont volontairement pas attestées.

Les tests natifs ajoutent offsets/footers falsifiés, identités chevauchantes,
références futures, accès brut chiffré, provenance Contents et budgets cumulés.
La CI exécute cet oracle et les contrôles existants sur PHP 8.3, 8.4 et 8.5.
Les clauses sources et le périmètre figurent dans
[la correspondance normative](../../resources/revision-history.json).

[Composants](components.md) · [Sécurité](security.md) · [MAC source](source-mac.md)
