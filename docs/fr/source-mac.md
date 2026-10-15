# Authentifier le MAC d’un PDF source

[English](../source-mac.md) | Français

Le lecteur 0.4 utilise le vérificateur du core 0.9 pour les MAC PDF
**ISO/TS 32004:2024**. Un `StandardPasswordAuthentication` choisi explicitement
authentifie le mot de passe et les permissions chiffrées, vérifie tout MAC
signalé sur les octets originaux, puis autorise la lecture du catalogue et du
contenu protégé. Un MAC corrompu, obligatoire mais absent, non pris en charge
ou périmé produit une erreur, sans repli vers un déchiffrement non authentifié.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\SourceIntegrityPolicy;
use PDF\Reading\Security\SourcePdfMacVerification;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\PreparedPassword;

$authentication = new StandardPasswordAuthentication(
    new PreparedPassword($preparedUtf8Password),
    mac: new SourcePdfMacVerification(maximumTokenBytes: 256 * 1024),
);
$reader = new DocumentReader(
    authentication: $authentication,
    sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac,
);
$document = $reader->read(new FileBytes($bytes));
// Standalone ou AttachedToSignature ; ce n’est pas une décision de confiance.
$location = $document->mac();
$permissions = $document->permissions();
```

La politique compatible par défaut, `AcceptUnprotected`, lit aussi les PDF non
protégés. Elle vérifie cependant tout MAC signalé par `AuthCode`, `KDFSalt` ou
le bit 13 des permissions authentifiées. `RequirePdfMac` refuse en plus une
source non protégée, y compris lorsque tous les indices de chiffrement et de
MAC ont été supprimés. Un PDF clair ordinaire ne permet pas de déduire qu’ils
ont existé. Choisir la politique obligatoire lorsque l’application attend une
entrée protégée.

Les entrées optionnelles AuthCode et KDFSalt résolues en null PDF sont absentes,
y compris via une chaîne bornée de références ou une génération indisponible.
Un AuthCode null dans la dernière révision ne réactive jamais un ancien MAC.
Les vrais dictionnaires AuthCode et chaînes de sel restent directs aux endroits
imposés par TS 32004 ; le bit 13 obligatoire et `RequirePdfMac` restent appliqués.

`DocumentReader::sourceIntegrityPolicy()` expose la politique configurée.
`requiringSourceIntegrity($policy)` compose un lecteur immuable avec les mêmes
limites et identifiants, en conservant ou renforçant l’exigence. Passer
`AcceptUnprotected` ne relâche pas un lecteur qui exige déjà un PDF MAC.

## Couverture exacte de la source

Le parseur conserve l’étendue lexicale de la véritable chaîne de sécurité,
délimiteurs compris. Deux chaînes identiques d’objets ou de révisions distincts
restent distinctes. Les valeurs issues d’un flux d’objets décodé n’acquièrent
pas d’étendue dans le fichier original. La présence d’octets semblables trouvés
par une recherche ne constitue pas une preuve.

| Emplacement | Liaison à la source vérifiée |
| --- | --- |
| `Standalone` | Le dernier trailer physique contient un dictionnaire AuthCode direct. Son ByteRange direct de quatre entiers couvre tout le fichier courant sauf son véritable jeton MAC hexadécimal direct. Les octets hexadécimaux doivent être complets : aucun espace interne, demi-octet final, octet supplémentaire exclu ou bourrage CMS. SigObjRef doit être absent. |
| `AttachedToSig` | AuthCode contient un SigObjRef indirect et aucun MAC ni ByteRange. Le ByteRange direct du dictionnaire de signature référencé exclut exactement son véritable jeton Contents hexadécimal direct. Un unique objet CMS DER est accepté, suivi seulement d’un bourrage de signature nul. Un unique attribut non signé PDF MAC et une seule valeur lient les octets du fichier et ceux de SignerInfo.signature. Les signatures numériques et horodatages documentaires sont pris en charge. |

L’AuthCode hérité d’un ancien trailer ne peut pas authentifier une nouvelle
révision. Le dernier MAC est vérifié sur toute la source courante, anciennes
révisions comprises, conformément à l’annexe B. Le lecteur ne revalide pas
séparément l’ancien MAC de chaque révision historique. Ajouter même des espaces
périme un MAC. Les références croisées en table, flux ou hybrides conservent
leurs bornes habituelles. La vérification précède l’interprétation du catalogue
ou du contenu chiffré.

## Frontière cryptographique

L’authentification native par mot de passe prend en charge Standard R5/V5,
R6/V5 et R7/V6, avec un KDFSalt direct de 32 octets. Le vérificateur utilise
la véritable clé de fichier authentifiée : HKDF-SHA256 avec `PDFMAC`, désenveloppage
AES-256 et HMAC-SHA256. Il contrôle CMS AuthenticatedData, son destinataire, les
attributs obligatoires, la protection d’algorithmes facultative et les condensats
du document et de la signature. SHA-256/384/512 et SHA3-256/384/512 sont pris en
charge. Les identifiants SHA-2 acceptent des paramètres absents ou NULL selon
RFC 5754 ; les autres paramètres restent contraints.

Cela authentifie le fichier avec sa clé de chiffrement. Cela ne vérifie **pas**
mathématiquement la signature sous-jacente, n’établit pas la confiance dans un
certificat ou une TSA, ne récupère aucun certificat ni donnée de révocation et
n’accorde aucune permission supplémentaire. Tout détenteur de la clé, dont une
personne possédant le mot de passe utilisateur, peut produire un nouveau MAC.
Un mot de passe utilisateur vide n’ajoute pas de protection face à quelqu’un
qui peut dériver cette clé. Une authentification MAC réussie ne rend pas les
actions et pièces jointes du PDF sûres à exécuter.

Adobe.PubSec s5 V5/V6 utilise la clé authentifiée de 256 bits de `DefaultCryptFilter`,
même si `StmF`, `StrF` ou `EFF` sélectionne un autre filtre. Les permissions viennent
des enveloppes CMS, jamais de l’ancien champ `P`. Voir [l’authentification par clé publique](public-key-authentication.md).
Les emplacements MAC inconnus et les autres mécanismes d’enveloppage CMS ou de MAC restent explicitement refusés.
Les conteneurs attachés exigent actuellement Contents hexadécimal direct,
ByteRange direct et objet signature source non compressé. Ce lecteur borné
n’est pas un validateur complet de conformité PDF. Un `DocumentAuthentication`
personnalisé est une capacité de confiance de l’application : son contexte de
déchiffrement doit préserver la même frontière de vérification.

## Limites et vérifications indépendantes

`SourcePdfMacVerification` limite CMS à 1 Mio par défaut, y compris la capacité
Contents et son bourrage pour une signature attachée. Le jeton source doit
aussi respecter `ReaderLimits::maxTokenBytes` ; son encodage hexadécimal occupe
deux caractères par octet décodé. Les étendues source, le tampon des octets
couverts, le texte clair et les flux décodés consomment les budgets cumulés de
conservation ou de décodage du document. La taille source est contrôlée avant
l’authentification. Ces bornes ne promettent pas une taille mémoire PHP exacte.

Les [fixtures conservées](../../tests/Fixtures/pdf-mac/README.fr.md) et
`tools/validate_source_mac.py` couvrent 15 vrais fichiers : six condensats, les
deux emplacements MAC, un horodatage documentaire, des flux d’objets, des mises
à jour incrémentales et le chiffrement R5–R7. pyHanko et cryptography vérifient
indépendamment CMS/HKDF/AESKW/HMAC/condensats ; qpdf contrôle les 13 fichiers
non R7 sans avertissement. qpdf 12.4.2 ne gère pas AESV4 et pyHanko n’authentifie
pas R5 historique : R7 utilise pyHanko/cryptography inchangés ; R5 associe les
identifiants pypdf/qpdf, cryptography et le validateur de jetons de pyHanko.
Aucun oracle n’est modifié.

Quatorze fichiers adverses générés indépendamment incluent des MAC authentiques
avec une mauvaise couverture ou des assertions incohérentes, des attributs CMS
dupliqués ou absents, une mauvaise liaison à la signature et des MAC hérités
périmés. Les tests natifs modifient aussi les commentaires, des octets hors du
texte chiffré, les marqueurs obligatoires, permissions, bourrage de signature
et longueur finale. Le refus est vérifié avant le retour d’un `ReadDocument`.

Sources : ISO/TS 32004:2024, tableaux 2–6 (pages physiques 10–12), §§6.2–6.6
(pages 13–17), annexe B (pages 19–20) ; l’original est conservé dans
[les documents de référence du core](https://github.com/PDFToolkit/pdf/tree/master/resources/reference-documents).
[RFC 5754 §2](https://www.rfc-editor.org/rfc/rfc5754#section-2) et
[RFC 6211 §3.2](https://www.rfc-editor.org/rfc/rfc6211#section-3.2) précisent
la compatibilité des identifiants d’algorithmes.

[Authentification par mot de passe](authentication.md) · [Sécurité](security.md)
