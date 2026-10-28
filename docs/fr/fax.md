# Décodage fax CCITT

[English](../fax.md)

`StreamDecoder` et `ReadDocument::streamData()` décodent `CCITTFaxDecode` en lignes monochromes complètes, bit de poids fort en premier, complétées à l'octet. Le flux encodé et ses octets source restent intacts. L'implémentation est native PHP et ne fait appel à aucun exécutable d'image ni paquet optionnel à l'exécution.

Le décodeur implémente Modified Huffman (1D), le codage mixte 1D/2D et le groupe 4 (2D), avec codes terminaux et de prolongation, modes pass/horizontal/vertical et extension non compressée T.4/T.6. Les sélecteurs d'extension non définis échouent explicitement. Toutes les valeurs positives de `K` sélectionnent le même décodeur mixte ; elles n'imposent pas des cadences différentes au décodeur. Le codage mixte commence par une ligne 1D.

## Paramètres et terminaison

Tous les paramètres de la table 11 PDF sont lus avec leurs types et valeurs par défaut : `K`, `Columns`, `Rows`, `EndOfLine`, `EncodedByteAlign`, `EndOfBlock`, `BlackIs1` et `DamagedRowsBeforeError`. Les valeurs indirectes utilisent le résolveur d'objets explicite du document. La largeur doit être positive ; le nombre de lignes et le nombre d'erreurs autorisé doivent être positifs ou nuls.

Les marqueurs EOL sont acceptés même si `EndOfLine` est faux et obligatoires s'il est vrai. L'alignement à l'octet vérifie les zéros de remplissage insérés. `EndOfBlock=true` exige le RTC/EOFB approprié et prévaut sur `Rows`. Avec `EndOfBlock=false`, le décodage s'arrête à `Rows` si ce nombre est positif ou lorsque les données encodées disponibles sont épuisées ; `Rows=0` est valide. Chaque ligne de sortie est complétée par des bits zéro situés hors des échantillons déclarés par `Columns`. `BlackIs1` détermine la polarité. La fin des données consomme le dernier octet partiel ; le remplissage de bloc par des zéros après EOFB est accepté et les données suivantes qui ne sont pas du remplissage sont rejetées.

Les motifs T.6 non compressés peuvent traverser les limites de lignes. `CcittFaxUncompressed` conserve au maximum cinq pixels en attente et la couleur explicite de sortie ; il n'alloue pas de tableau de pixels couvrant toute l'image. T.4 sort du mode non compressé avant la synchronisation de ligne. Les codes de sortie produisant zéro à quatre pixels blancs et les deux couleurs du prochain run sont couverts séparément.

## Récupération et budgets

La récupération n'est activée que par `DamagedRowsBeforeError` positif avec `EndOfLine=true` et `K` positif ou nul. Une ligne malformée déclenche une recherche bornée du prochain EOL. Le remplacement est la ligne saine précédente ou une ligne blanche si la précédente était également endommagée ; une première ligne endommagée devient blanche. Le compteur est cumulatif dans le flux. Les dépassements de ressources et extensions non définies ne deviennent jamais des erreurs de ligne récupérables. Aucune autre correction ou resynchronisation implicite n'est effectuée.

Le `ReadBudget` partagé comptabilise cumulativement les octets décodés et les données de lignes/transitions retenues. `ReaderLimits` borne l'entrée encodée et la taille du flux décodé avant allocation. `CcittFaxLimits` borne aussi les lectures de bits (anticipation et récupération incluses), les lignes, les transitions par ligne et la recherche de récupération. Ses valeurs par défaut sont 134 217 728 lectures de bits, 1 000 000 lignes, 1 000 000 transitions par ligne et 1 048 576 bits de recherche. Un appel direct à `CcittFaxDecoder` peut fournir des limites de travail adaptées avec le même budget du document :

```php
$decoder = new CcittFaxDecoder(
    $readerLimits,
    new CcittFaxParameters(k: -1, columns: 1728),
    new CcittFaxLimits(maxRows: 10000),
);
$pixels = $decoder->decode($encoded, $documentBudget);
```

Ces classes appartiennent à `PDF\Reading\Filters` ; `$documentBudget` est un `PDF\Reading\ReadBudget`. Chaque invocation possède son état mutable de bits/lignes ; la configuration immuable est réutilisable.

## Cadrer un préfixe sans deviner les délimiteurs PDF

`CcittFaxDecoder::decodePrefix(ByteCursor, ReadBudget)` utilise le même automate T.4/T.6 que `decode()`. Il consomme exactement un préfixe encodé dont la fin est déterminable et son remplissage zéro jusqu’à l’octet suivant. Le résultat immuable `CcittFaxPrefix` expose `samples`, `encodedBytes`, `rows`, `bitReads` et `transitions` ; le curseur fourni reste au premier octet suivant. Son décalage initial est pris en charge sans copier le contenu restant. L’anticipation restaure le curseur tout en conservant la charge de travail.

Un préfixe exige `EndOfBlock=true` ou `Rows` positif. Sans l’un de ces critères, seul un flux complet borné séparément permet d’établir l’épuisement ; l’API de préfixe refuse de deviner une frontière. Reader ne recherche jamais `EI` et n’interprète pas la syntaxe du contenu PDF. L’appelant valide son propre délimiteur suivant. Les octets zéro après la première frontière alignée restent à l’appelant ; `decode()` vérifie toujours l’entrée complète et n’accepte que le remplissage zéro autorisé après terminaison, même lorsque `Rows` fournit la frontière.

Chaque appel possède son état mutable. Octets décodés, stockage des transitions et anticipation conservent les mêmes bornes d’octets/travail. Les compteurs du résultat permettent au parseur contenant de débiter également son budget cumulatif de document. L’oracle JSON vérifie les échantillons et décalages exacts des 39 cas du corpus dont la fin est déterminable ; le cas restant `Rows=0, EndOfBlock=false` reste pris en charge uniquement comme flux complet. Les régressions couvrent aussi `EI` adjacent, des octets `EI` littéraux dans des pixels non compressés, un remplissage invalide et l’anticipation bornée.

## Preuves et limites

`tools/build_fax_fixtures.py` conserve 40 petits flux d'images PDF synthétiques : 14 cas encodés indépendamment par libtiff (MH, G3, G4, alignement, largeurs étroites/non multiples de huit, longues séquences de prolongation, EOL facultatif, terminaison et équivalence des K positifs) et 26 vecteurs explicites des tables ITU pour le mode non compressé. Les TIFF originaux, empreintes des données encodées et bitmaps attendus sont dans `tests/Fixtures/fax`. Le générateur n'importe aucun décodeur PHP.

`tools/validate_fax.py --php php --report _build/fax/report.json` contrôle la reproductibilité, le chemin réel Reader PDF→image→octets décodés, la liaison du flux encodé par pypdf, la structure qpdf, les pixels source Pillow/libtiff et les pixels décodés par Poppler pour les 14 cas compressés. Il rend également un PDF contenant la sortie décodée de chaque cas. Ce dernier contrôle vérifie la représentation des échantillons en sortie ; **il ne constitue pas un oracle de décodage indépendant pour l'extension non compressée**. Pour cette extension, les preuves reposent sur les bitmaps normatifs explicites, les vecteurs exhaustifs des codes de sortie, les tests de transitions et une revue séparée des sources. Poppler/Ghostscript et les autres codecs externes examinés ne fournissent pas d'oracle fiable pour l'ensemble de ces cas d'extension. Le rapport JSON distingue ces catégories de preuves.

Les tests PHP couvrent aussi polarité/remplissage, codes et paramètres malformés, marqueurs manquants, budgets cumulatifs, récupération bornée des lignes endommagées et erreurs explicites pour les extensions non définies. Les outils Python nécessitent les dépendances de validation de développement, qpdf et Poppler. Ne régénérer les échantillons libtiff avec `--refresh` que volontairement ; le contrôle ordinaire de reproductibilité utilise `--check`.

## Références normatives

- ISO 32000-2:2020, §7.4.6 et table 11, pages physiques 58–59 de la référence EC3 conservée (pages imprimées 43–44).
- [UIT-T T.6 (11/1988)](https://www.itu.int/rec/dologin_pub.asp?id=T-REC-T.6-198811-I%21%21PDF-E&lang=e&type=items), tables 1–4 et §§2.2–2.4. Concaténation non compressée en page physique 10, table 4 et EOFB/remplissage en page physique 11 (pages imprimées 8–9).
- [UIT-T T.4 (04/1999)](https://www.itu.int/rec/dologin_pub.asp?id=T-REC-T.4-199904-S%21%21PDF-E&lang=e&type=items), tables 4–5 et §§4.2.1–4.2.4, pages physiques 18–19 (pages imprimées 10–11).
