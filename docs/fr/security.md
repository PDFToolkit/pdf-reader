# Contrôles de sécurité du lecteur

[English](../security.md) | Français

Le lecteur analyse des octets PDF externes. Cette documentation décrit les
protections implémentées et les scénarios couverts par les tests du projet.
Elle ne constitue pas un audit externe ni une garantie d'absence de
vulnérabilité. Le périmètre inclut la syntaxe, la résolution des objets, les
révisions, les pages et les filtres pris en charge.

Sans capacité d’authentification, une entrée `/Encrypt` effective non null
et résolue en dictionnaire lève `EncryptedPdfException`. Les références null
signifient l’absence ; une valeur d’un autre type est une erreur d’analyse.
Une capacité `StandardPasswordAuthentication` injectée authentifie avant
l’interprétation du catalogue, vérifie les permissions chiffrées R5–R7 et
déchiffre les objets une seule fois sous les budgets du document.
Voir le [guide d’authentification](authentication.md) pour R2–R7, la préparation
des mots de passe, la [vérification des PDF MAC source](source-mac.md) et
[l’authentification par clé publique](public-key-authentication.md).

## Surfaces contrôlées

| Surface | Contrôles et tests |
| --- | --- |
| Source | Header PDF 1.0–1.7 ou 2.0 au début des bytes, footer final borné, refus des sources dépassant la limite. Aucun chargement de chemin ou d'URL par le lecteur. |
| Syntaxe | Longueurs des tokens, profondeur et nombre de valeurs ; échappements des noms et chaînes ; tableaux et dictionnaires bornés ; refus des clés dupliquées et des nombres hors plage. |
| Curseurs | Chaque vue possède des bornes ; les lectures et déplacements hors vue sont refusés. Les objets comprimés sont analysés dans des tranches séparées. |
| Xref | Offsets, générations, types et plages vérifiés ; champs big endian sans dépassement d'entier ; sous-sections et `/Index` sans chevauchement. |
| Révisions | Chaîne `/Prev` bornée et cycles détectés ; les entrées récentes, y compris libres, masquent les anciennes. Une génération périmée se résout à null. |
| MAC source | Vérification liée à la clé Standard R5–R7 ou Adobe.PubSec avant le contenu, étendue du véritable jeton source, couverture exacte du fichier entier, syntaxe autonome stricte, bourrage de signature borné, CMS/HMAC et condensats. La politique obligatoire refuse aussi les sources claires ou privées de protection. |
| Objets | Identifiant réel comparé à l'entrée xref ; chargements récursifs bornés et cycles de chargement détectés ; caches propres au document. |
| Arbre de pages | Parcours avec pile explicite ; limites propres de profondeur, de nœuds et de feuilles ; refus des cycles, descendants répétés, comptes incohérents et liens Parent incorrects. |
| Héritage | Types des ressources, rectangles et rotations vérifiés ; quatre entrées héritables seulement ; ressources locales remplaçant les valeurs héritées sans fusion ; rotation multiple de 90. |
| Contenus de page | Page liée à son document source ; nombre de flux borné avant résolution du tableau ; chaque élément doit désigner un flux ; aucun héritage, décodage ou interprétation automatique. |
| Streams | Étendue déterminée exclusivement par `/Length`. Les marqueurs de fin contenus dans les données binaires ne découpent pas le flux. Les longueurs indirectes utilisent le résolveur borné. |
| Données conservées | Budget cumulé des noms, chaînes et octets encodés, contrôlé avant leur conservation ; des vues répétées ou chevauchantes ne peuvent pas multiplier ces copies sans plafond. |
| ObjStm | Contrôle de `/N`, `/First`, numéros et offsets ; une valeur par tranche ; validation du numéro désigné par l'index xref ; génération zéro et longueur non comprimée. |
| Décompression | Limite sur chaque sortie, budget cumulé sur toutes les étapes et longueur de chaîne de filtres ; décompression Flate progressive ; refus des données tronquées et des lignes de prédicteur invalides. |
| Mécanismes externes | Pas d'exécution d'actions PDF ou JavaScript. Les flux externes exigent une capacité applicative explicite ; tentatives, octets acquis et décodage partagent les plafonds configurés. Voir les [flux externes](external-streams.md). |
| Écriture | Références et streams externes refusent leur sérialisation sans import et remappage ; aucun fichier de sortie n'est ouvert par le lecteur. |

Les tests [ReadingSyntaxTest](../../tests/ReadingSyntaxTest.php),
[ReadingFiltersTest](../../tests/ReadingFiltersTest.php),
[ReadingDocumentTest](../../tests/ReadingDocumentTest.php),
[ReadingFileStructureTest](../../tests/ReadingFileStructureTest.php),
[ReadingRetentionTest](../../tests/ReadingRetentionTest.php) et
[ReadingPagesTest](../../tests/ReadingPagesTest.php) exercent ces frontières.
Les cinq [fixtures externes](../../tests/Fixtures/pdf/README.md) proviennent d'un
générateur pypdf et d'une transformation qpdf, indépendants du writer PHP.
Les deux [fixtures de pages](../../tests/Fixtures/page-tree/README.md) ajoutent
un arbre à plusieurs niveaux, des attributs hérités ou locaux et des tableaux
de contenus directs ou indirects, y compris des données binaires.
Leur reproduction vérifie les bytes, les lectures strictes et les résultats
structurels attendus. Une bonne lecture de ces fixtures ne démontre pas la
conformité à tous les PDF possibles.

## Limites par défaut

| Propriété de `ReaderLimits` | Plafond |
| --- | --- |
| `maxFileBytes` | 64 MiB de source. |
| `maxDepth` | 64 niveaux de syntaxe ou de chargement indirect. |
| `maxTokenBytes` | 1 MiB par token ; borne également la recherche du footer. |
| `maxContainerEntries` | 100 000 entrées dans un conteneur. |
| `maxObjects` | 100 000 pour le plafond d'objets et la borne des numéros structuraux. |
| `maxCrossReferenceEntries` | 200 000 entrées cumulées sur les révisions. |
| `maxRevisions` | 64 sections dans la chaîne `/Prev`. |
| `maxDecodedStreamBytes` | 64 MiB pour une sortie de décodage. |
| `maxTotalDecodedBytes` | 128 MiB cumulés sur les étapes de décodage. |
| `maxFilters` | 16 filtres dans une chaîne. |
| `maxParsedValues` | 500 000 valeurs syntaxiques cumulées. |
| `maxRetainedBytes` | 128 MiB cumulés pour les noms, chaînes et octets de streams conservés. |

Le budget décodé compte les sorties intermédiaires, par exemple la sortie
Flate puis la sortie du prédicteur. Les flux xref et les flux d'objets utilisent
le même budget que les autres flux. Un accès au résultat déjà décodé dans le
cache de `ReadDocument` ne déclenche pas un nouveau décodage.

Ces plafonds sont des limites de travail, pas une promesse sur le maximum de
mémoire PHP consommée. La source, les objets, les dictionnaires et les caches
occupent de la mémoire supplémentaire. Un décodeur natif peut aussi produire
une tranche temporaire avant son contrôle. Les valeurs par défaut doivent
être adaptées au budget mémoire et aux quotas de l'application.

Les opérations de pages ajoutent leurs plafonds : `PageTreeLimits` autorise
par défaut 64 niveaux, 100 000 nœuds et 100 000 pages ; `PageContentLimits`
autorise 1 024 flux par page. Ces compteurs appartiennent à une opération.
Les objets chargés et les octets décodés restent soumis aux budgets cumulatifs
du même `ReadDocument`. Consulter le [guide des pages](pages.md)
pour les configurer explicitement.

## Responsabilités de l'application

L'application choisit et autorise l'origine des bytes. Elle doit borner leur
acquisition avant de construire `FileBytes`, notamment pour un upload ou une
réponse réseau. `maxFileBytes` est contrôlé lorsque les bytes sont déjà fournis
au lecteur. Il ne contrôle pas le téléchargement ou l'ouverture d'un fichier
réalisés en amont.

Le lecteur ne supprime pas les actions, pièces jointes ou autres objets
potentiellement actifs du PDF. Leur existence est conservée comme donnée.
Présenter ou redistribuer ensuite le fichier original reste une décision de
l'application ; la réussite de la lecture n'est pas une certification que le
document peut être ouvert sans risque par n'importe quel logiciel.

La résolution est à la demande. L'ouverture contrôle le catalogue et les xref,
mais ne visite pas tous les objets ni toutes les ressources. Un défaut dans un
objet non encore chargé peut être signalé lors d'un accès ultérieur. Le lecteur
ne répare pas les fichiers endommagés et refuse les mécanismes non pris en
charge, notamment les PDF MAC non vérifiés, les handlers inconnus
et les codecs de décompression propres aux images.

Les diagnostics et valeurs extraites ne doivent pas être envoyés dans une
page HTML sans échappement ni publiés sans contrôle d'accès. Les données
contenues dans les dictionnaires, noms et chaînes proviennent du document.
L'application décide de ce qu'elle peut journaliser ou présenter.

## Vérifications et dépendances

La CI exécute les tests, l'analyse statique et le contrôle de format sur PHP
8.3, 8.4 et 8.5. `composer audit --locked` vérifie les avis disponibles pour les
dépendances verrouillées ; ce contrôle ne constitue pas un audit du code du
lecteur et dépend des informations connues au moment de son exécution.

PHP, zlib, le serveur, les outils de validation et les logiciels ouvrant les
PDF ont leurs propres surfaces de sécurité, hors du périmètre des tests de
cette bibliothèque.

[Signalements privés](../../SECURITY.fr.md) · [Architecture](architecture.md)

Les tests indépendants comparent 26 cas LZW/prédicteurs à qpdf, six documents
hybrides à pypdf/qpdf et 24 PDF R2–R7 chiffrés par qpdf ou cryptography.

Le patch 0.3.1 vérifie aussi sept sources pour le propriétaire des métadonnées et
les types indirects des fichiers incorporés. `EncryptMetadata=false` exempte
uniquement l'identité Metadata réelle du catalogue, pas les flux arbitraires
déclarant `/Type /Metadata`. Le contexte immuable reçoit la racine explicitement
et utilise le résolveur borné ; Identity explicite et octets des codecs restants
sont préservés. Le [guide d'authentification](authentication.md) distingue clauses
sources, limite de déchiffrement EFF de qpdf et fixtures cryptography indépendantes.
Les tags AES-GCM modifiés échouent sans produire de plaintext. Les erreurs
d’authentification ne révèlent ni credentials ni contenu du document.

## Inspection des révisions physiques

`readRevisionHistory()` ne déclenche pas l’authentification ni la politique MAC
d’un `DocumentReader`. Son graphe brut peut contenir du ciphertext et n’atteste
aucune permission. Les préfixes, index et étendues sont contrôlés et comptés dans
un budget partagé ; les objets historiques ne peuvent atteindre des octets futurs.
Une lecture authentifiée séparée applique la politique configurée aux octets
exacts choisis. Voir le [guide physique](revision-history.md) et
[ReadingRevisionHistoryTest](../../tests/ReadingRevisionHistoryTest.php).
