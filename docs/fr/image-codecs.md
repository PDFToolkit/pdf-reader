# Décodage d’images : filtres natifs et plans PHP

[English](../image-codecs.md) | Français

Le Reader 0.7 décode `DCTDecode`, `JPXDecode` et `JBIG2Decode` lors d’une demande explicite de décodage d’image ou de flux. Ouvrir un PDF, résoudre son catalogue et lire ses flux encodés ne lance aucun décodeur d’image. L’analyse des xref et des flux d’objets ne peut pas en activer. Le [fax CCITT](fax.md) reste un filtre PHP intégré distinct.

## Installation et sélection

Par défaut, `ImageCodecs\AutomaticDecoder` essaie d’abord l’adaptateur natif borné. Sous Linux, il exige `gs` (Ghostscript), `prlimit` (util-linux) et PHP `proc_open`, installés par l’application. La découverte parcourt les répertoires absolus du `PATH` de l’application ; `NativeTools` accepte aussi des chemins absolus explicites. Le Reader n’installe aucun programme et ne télécharge aucun codec.

Si les outils natifs sont absents, si un filtre n’a pas été compilé ou si son profil ne préserve pas les échantillons et métadonnées, l’adaptateur utilise le package facultatif en PHP pur :

```console
composer require "pdftoolkit/pdf-codecs:^0.1"
```

En son absence, `CodecUnavailable` contient cette commande. Une donnée malformée ou une limite dépassée ne déclenche jamais une seconde tentative avec un autre moteur. La bibliothèque PHP peut elle-même signaler une fonctionnalité non prise en charge ; son installation ne promet pas la réparation de fichiers endommagés arbitraires.

L’application peut choisir explicitement son moteur :

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\ImageCodecs\AutomaticDecoder;
use PDF\Reading\ImageCodecs\GhostscriptDecoder;
use PDF\Reading\ImageCodecs\ImageLimits;
use PDF\Reading\ImageCodecs\NativeTools;
use PDF\Reading\ImageCodecs\PhpDecoder;

$phpOnly = new DocumentReader(imageDecoder: new PhpDecoder());
$native = new GhostscriptDecoder(new NativeTools('/usr/bin/gs', '/usr/bin/prlimit'));
$automatic = new DocumentReader(
    imageDecoder: new AutomaticDecoder($native, new PhpDecoder()),
    imageLimits: new ImageLimits(maxPixels: 4_000_000, nativeWallMilliseconds: 5000),
);
```

`Decoder::decode(CodecRequest): DecodedImage` permet aussi un adaptateur applicatif. Ces contrats Reader ne dépendent pas des classes du package facultatif. Ghostscript est installé séparément et conserve ses propres [conditions de licence](https://www.ghostscript.com/licensing/) ; il n’est pas redistribué avec le Reader.

## Données exactes des composantes

Pour un `EncodedStream` résolu dans un `ReadDocument`, `$document->imageData($stream)` retourne l’image décodée. Les demandes d’image et d’octets partagent le même cache documentaire.

| Valeur | Signification |
| --- | --- |
| `DecodedImage::width`, `height`, `originX`, `originY` | Étendue intrinsèque de l’image et origine de sa grille de référence. |
| `components` | Objets `ComponentPlane` ordonnés : dimensions, précision 1–38, signe, séparation/origine de grille, `rowBytes` et `bytes`. |
| `ComponentPlane::sample($x, $y)` | Échantillon entier, avec extension du signe pour une composante signée. |
| `ComponentPlane::alpha` | `none`, `unassociated` ou `associated` ; les plans couleur et alpha restent séparés. |
| `color` | Désignation d’espace couleur, valeur énumérée, octets ICC exacts et paramètres ; toutes les spécifications conservent méthode, priorité, approximation et données brutes de méthode, avec l’indice sélectionné. |
| `channelDefinitions` | Canal/type/association d’origine et plan de sortie correspondant. |
| `chromaKey` | Valeurs JPX `opct` de type 2 conservées. PDF ignore ce mécanisme d’opacité par clé chromatique ; le Reader ne le transforme pas en alpha. |
| `backend` | `ghostscript`, `php` ou la désignation de l’adaptateur applicatif. |

Les octets des plans sont empaquetés bit de poids fort en premier, chaque ligne étant complétée jusqu’à l’octet. Ce ne sont pas des fichiers PNG. `streamData()` entrelace uniquement des plans uniformes non signés, de pleine résolution, à origine nulle et sans alpha. Une grille distincte, un échantillon signé ou un canal alpha provoque un `CodecUnavailable` orientant vers `imageData()`, sans réduction silencieuse. Une précision uniforme non signée est conservée même lorsqu’elle n’est pas de huit bits.

L’appelant applique encore le dictionnaire d’image PDF : priorité de l’espace couleur, `Decode`, masques, `SMaskInData`, composition du masque doux et transformations d’affichage. Conserver des octets ICC ne constitue pas un moteur de gestion des couleurs. Aucun codec n’accorde de permissions documentaires ni d’autorité de signature.

## Sémantique des filtres et profils natifs

### DCT / JPEG

Le JPEG Huffman huit bits séquentiel et progressif comprend le sous-échantillonnage, les intervalles de redémarrage, les scans séparés/entrelacés et les transformations Adobe. APP14 prime sur `ColorTransform` ; à défaut, le paramètre explicite s’applique, puis PDF transforme par défaut les images à trois composantes et ne transforme pas les autres. Les identifiants de composantes RGB et les heuristiques JFIF ne remplacent pas ce défaut PDF. YCbCr donne RGB ; YCCK donne les composantes CMYK PDF. Les données à quatre composantes ne sont pas automatiquement inversées ou converties en RGB d’affichage. Les fragments ICC sont conservés.

Les arrondis IDCT et le suréchantillonnage chromatique peuvent différer entre moteurs. PHP utilise une expansion par plus proche voisin ; la bibliothèque JPEG native peut interpoler la chrominance. Les contrôles indépendants comparent les échantillons de pleine résolution avec des tolérances d’arrondi déclarées et ne prétendent pas à l’identité binaire de tous les rendus JPEG sous-échantillonnés. Une application exigeant une seule politique d’expansion reproductible peut choisir `PhpDecoder`. Les autres processus JPEG et précisions échouent explicitement lorsqu’aucun moteur installé ne prend en charge le profil PDF.

### JPX / JPEG 2000

L’adaptateur natif n’accepte que les plans d’origine qualifiés : une, trois ou quatre composantes non signées, de même précision 1, 2, 4, 8 ou 16 bits, à séparation unitaire, origine nulle et profil de codestream pris en charge. Une déclaration couleur simple Gray/RGB/CMYK est conservée. Palettes, correspondances de canaux, alpha, ICC ou spécifications couleur alternatives, métadonnées de couches/opacité, autres précisions, échantillons signés et grilles distinctes sélectionnent PHP avant tout processus natif. Les marques de fichier JPX, exigences de lecture, fragments et conteneurs de composition sélectionnent également PHP avant d’exiger un codestream contigu. L’inspection contrôle aussi les limites de tuiles et de boîtes.

Le résultat PHP conserve la précision, les plans et les métadonnées couleur/canaux de la bibliothèque de codecs. Il ne réduit pas les échantillons de 12, 24 bits ou davantage à huit bits. Les extensions JPEG 2000 absentes restent des échecs explicites, sans approximation rendue. Le [package de codecs](https://github.com/PDFToolkit/pdf-codecs) documente les mécanismes de codestream et métadonnées implémentés.

### JBIG2

Les échantillons suivent la convention PDF : zéro est noir, un est blanc. `JBIG2Globals` est résolu, authentifié/déchiffré et décodé par le même pipeline documentaire avant la demande d’image. Les globals partagés utilisent le cache ; récursion et budgets cumulés couvrent toute la chaîne. L’adaptateur natif valide les limites et références des segments embarqués, puis transmet les segments globaux de page zéro suivis des segments de page au filtre natif. Il ne transmet pas le dictionnaire PDF comme code exécutable.

Les flux embarqués sans segment de fin de page exigent Ghostscript 10.03.0 ou plus récent. Les anciens filtres publics peuvent ne pas terminer une page lorsque EOF arrive séparément des derniers octets ([source amont 10.02.1](https://github.com/ArtifexSoftware/ghostpdl/blob/ghostpdl-10.02.1/base/sjbig2.c), [correction 10.03.0](https://github.com/ArtifexSoftware/ghostpdl/blob/ghostpdl-10.03.0/base/sjbig2.c)). Une garde de révision fixe précède la lecture : la sélection automatique utilise PHP sur les versions concernées ; une demande native explicite lève `CodecUnavailable`. Aucun segment terminal n’est injecté et la source encodée reste inchangée.

Les mécanismes natifs incompatibles connus sont détectés avant exécution : régions intermédiaires texte/demi-teinte/génériques/raffinement ; contextes bitmap conservés ; dictionnaires/texte à raffinement Huffman ; drapeau des douze gabarits adaptatifs ; demi-teinte `HDEFPIXEL=1` ; segments d’extension ; longueurs de segments inconnues et pages en bandes/à hauteur inconnue. Ils passent par PHP. Cette sélection prudente évite les comportements absents ou incorrects confirmés dans libjbig2dec 0.20. Elle ne prétend pas que la bibliothèque native implémente ces mécanismes. Les associations de page non normalisées passent également par PHP ; le décodage n’est pas une validation exhaustive de conformité PDF.

## Limites et isolation du processus

`ReaderLimits` continue de borner entrée, sortie, imbrication, données conservées et octets décodés cumulés. `ImageLimits` ajoute pixels, dimensions, composantes, métadonnées, segments, tuiles, travail et mémoire intermédiaire. Le précontrôle natif impute inspection des octets encodés, index des segments/état ICC et assemblage des plans à un budget partagé ; le codec natif lui-même est borné séparément par les limites CPU/durée/mémoire du processus. Les dimensions et quantités d’octets natives sont vérifiées ; les métadonnées conservées sont imputées au budget documentaire. Les globals filtrés consomment la même enveloppe cumulée d’octets décodés que les échantillons de page.

L’exécution native emploie un tableau d’arguments, un programme fixe de filtre et l’entrée standard binaire. Aucun shell, interprète du PDF fourni ou périphérique de rendu n’intervient. L’image fournie ne devient jamais du code PostScript. `GS_OPTIONS`, `GS_LIB`, la configuration du répertoire personnel et les autres variables héritées sont retirés. `prlimit` borne espace d’adressage, CPU, descripteurs et écritures de fichiers ; le parent borne aussi durée écoulée, stdout et stderr et termine un processus dépassant ces plafonds. Il s’agit d’une exécution bornée, sans prétendre à un bac à sable complet du système d’exploitation.

Les défauts sont 16 777 216 pixels, 65 536 par dimension, 16 composantes, 100 000 000 unités de travail, 256 Mio d’allocation PHP intermédiaire, 4 Mio de métadonnées, 100 000 segments et 4 096 tuiles. Côté natif : 768 Mio d’espace d’adressage, 10 secondes CPU, 15 secondes écoulées et 64 Kio de diagnostics. L’application peut réduire ou augmenter ces plafonds explicites. La comptabilité est prudente ; les copies d’entrée et le remplissage des lignes comptent aussi.

| Échec | Résultat |
| --- | --- |
| Outils/package absents ou profil ne préservant pas les échantillons | `CodecUnavailable`, sous-type de `UnsupportedPdfFeature`. Seule cette condition sélectionne le moteur suivant. |
| Données malformées ou avertissement/réparation natif | `ParseException` ; aucun repli. |
| Limite de sortie, géométrie, mémoire, travail ou processus | `CodecLimit`, sous-type de `ParseException` ; aucun repli. |

## Reproduire la validation

```console
python -m pip install --require-hashes -r tools/validation-requirements.txt
python tools/validate_image_codecs.py --php php --require-native --report _build/image-codecs.json
```

Il faut le package PHP facultatif, Ghostscript/prlimit, qpdf et `pdfimages` de Poppler. `--php`, `--qpdf` et `--pdfimages` acceptent des chemins d’exécutables configurés. Sans `--require-native`, l’oracle accepte aussi un environnement PHP seul ; le rapport distingue ce cas.

Le corpus durable couvre les plans de référence OpenJPEG exacts, les bitmaps JBIG2 déclarés et les décisions de profil natif, des comparaisons JPEG Pillow/libjpeg et Poppler réelles, ainsi que des PDF clairs/AES-256 aux globals Flate contrôlés par pypdf, qpdf et les deux moteurs Reader. Les tests couvrent aussi analyse passive, cache partagé, classification du repli, cycles, budgets cumulés, délais natifs et métadonnées des échantillons. Les fichiers générés et leur provenance figurent dans [le dossier des fixtures](../../tests/ImageCodecs/Fixtures/README.md). Ce corpus fini et les outils indépendants apportent des preuves pour ces cas ; ils ne prouvent pas une conformité universelle.

Ancrages normatifs : ISO 32000-1/2 §7.4.7 (JBIG2), §7.4.8 (DCT), §7.4.9 (JPX) et §8.9 (interprétation des images PDF).
