# Lire des PDF chiffrés pour des certificats

[English](../public-key-authentication.md) | Français

`PublicKeyAuthentication` implémente Adobe.PubSec avec un `RecipientDecrypter`
du core fourni explicitement. Le lecteur reçoit les octets PDF de l’application,
récupère le seed d’une enveloppe CMS correspondante et authentifie les filtres
du document avant d’interpréter le catalogue. Il ne recherche aucune clé, ne
télécharge aucun certificat et n’établit aucune confiance PKI.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Reading\Security\PublicKeyAuthenticationLimits;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\RecipientDecrypter;

/** @var RecipientDecrypter $recipientDecrypter Identité choisie par l’application. */
$authentication = new PublicKeyAuthentication(
    $recipientDecrypter,
    PublicKeyPermissionByteOrder::MostSignificantFirst,
    new PublicKeyAuthenticationLimits(maxCredentialAttempts: 64),
);
$document = (new DocumentReader(authentication: $authentication))->read(new FileBytes($bytes));
$permissions = $document->permissions();
$effectiveMask = $permissions?->effectiveMask();
```

Pour des credentials PEM locaux, construire `OpenSslIdentity` du core avec une
`CertificateChain` explicite, les octets PEM de la clé privée et une phrase secrète
facultative, puis la transmettre à `OpenSslRecipientDecrypter`. Ce backend demande
`ext-openssl` et des fichiers temporaires dans un dossier choisi par l’application ;
il n’invoque aucun exécutable. Les autres capacités CMS restent des choix applicatifs.
Une liste non vide de déchiffreurs peut fournir des identités différentes pour le
document et ses pièces jointes. Les destinataires sont essayés dans l’ordre source,
puis les identités dans l’ordre applicatif. Un payload correspondant mais mal formé
échoue sans repli vers un destinataire plus permissif.

Lancer l’exemple applicatif avec un PDF explicite, un certificat DER et une clé
privée PEM non chiffrée (ou l’adapter pour fournir une phrase secrète de façon sûre) :

```sh
php examples/certificate.php document.pdf certificate.der private-key.pem
```

Il affiche le nombre de pages, les permissions effectives et l’emplacement du MAC vérifié.

| Mécanisme source | Opération prise en charge |
| --- | --- |
| s3/s4, V1/V2 | RC4 historique ; s3 et V1 utilisent 40 bits. |
| s5, V4 | Filtres nommés V2/RC4 et AESV2/AES-128. |
| s5, V5/V6 | Respectivement AESV3/AES-256 CBC et AESV4/GCM, avec PDF MAC source. |
| Identity | Passage inchangé standard, non redéfinissable par la source. |

Le `Length` d’un CF public-key est exprimé en **bits**, contrairement aux octets
des CF Standard. Une longueur absente utilise les défauts historiques : AESV2 128,
AESV3/AESV4 256, RC4 Length global ou 40. Cette lecture compatible n’atteste pas
la conformité ISO2. Les handlers personnalisés, méthodes inconnues et CFM None
restent refusés. None délègue le déchiffrement au handler ; il ne devient pas Identity.

## Faits des destinataires et permissions

Un payload CMS de portée document contient exactement 20 octets de seed puis
quatre octets de permissions. Une enveloppe de flux individuel contient exactement
les 20 octets de seed. La dérivation utilise ce seed récupéré et **tous les paquets
CMS originaux dans leur ordre original**, y compris ceux d’autres destinataires.
L’ancien champ de compatibilité `Encrypt.P` n’accorde aucun droit.

L’ordre des octets de permissions est un argument obligatoire :
`MostSignificantFirst` suit la sémantique déployée/corrigée ISO2 ;
`IsoLeastSignificantFirst` sélectionne le texte littéral ISO2008. Aucune heuristique
ne passe de l’un à l’autre. Le bit 2 public-key accorde tous les droits d’usage sans
représenter une authentification propriétaire Standard par mot de passe.

`ReadDocument::permissions()` retourne désormais `?DocumentPermissions`, contrat
commun `effectiveMask()`. Standard retourne toujours `ReadPermissions`, avec
`mask`, `access` et `revision`. Les certificats produisent `PublicKeyReadPermissions` ;
sa map `filters` expose des `PublicKeyRecipientAccess` avec le mot de permissions
authentifié et le premier `recipientIndex` correspondant, sans matériau de clé.
Lorsque les filtres par défaut ont des permissions différentes, `effectiveMask()`
retient prudemment leur intersection. L’accès à un flux individuel n’accorde pas
de droits supplémentaires sur le document. Pour les champs propres aux mots de
passe, l’application doit vérifier `instanceof ReadPermissions`.
Le `subFilter` typé conserve l’identité s3/s4/s5. Pour s3, seuls les bits d’usage
R2 historiques 3–6 sont pertinents ; les bits réservés ne représentent pas les
permissions granulaires ultérieures. Depuis 0.5.2, les `effectiveMask()` du
document et du destinataire traduisent ces quatre droits R2 dans le masque commun :
le remplissage dépend du bit 6, l’extraction d’accessibilité du bit 5, l’assemblage
du bit 4 et l’impression sans restriction de qualité du bit 3. Les bits source
9–12 n’accordent jamais ces droits pour s3. Le mot authentifié original reste
disponible dans `PublicKeyRecipientAccess::permissions`, avec son `subFilter`
explicite. Le bit 2 accorde toujours tous les droits PubSec. Pour s4/s5, les bits
granulaires conservent leur sens habituel. L’application doit appliquer le masque
effectif, sans interpréter un mot s3 brut comme un mot s4/s5.

## Filtres distincts et accès différé

Chaque filtre nommé possède sa propre clé récupérée et ses faits de destinataire.
DocOpen s’authentifie à l’ouverture. EFOpen ne s’authentifie qu’à l’accès au flux
protégé : l’absence de credentials d’une pièce jointe n’empêche pas d’ouvrir un
document par ailleurs accessible. Un EFOpen sélectionné par StrF ou StmF est traité
comme DocOpen, conformément à la table 25. Aucun repli vers une autre clé ou Identity
n’est permis. Crypt explicite utilise directement la clé du filtre ; le chiffrement
historique ordinaire utilise le numéro et la génération de l’objet propriétaire.

L’appartenance des métadonnées au catalogue, les identités terminales, les exemptions
signature/XRef et le déchiffrement unique des membres ObjStm suivent la même chaîne
bornée que Standard. Le flag metadata vient du filtre des flux ; seule la véritable
métadonnée du catalogue bénéficie d’une exemption implicite.

Pour PubSec moderne, tout PDF MAC signalé est vérifié avant le catalogue avec la
clé authentifiée de **DefaultCryptFilter**. Un DefaultCryptFilter de portée
document s’authentifie dès l’ouverture même déclaré EFOpen, pour que son bit MAC
obligatoire ne soit pas masqué par un accès différé. Les autres sélecteurs ne substituent pas
leurs clés. Le bit 13 authentifié, KDFSalt direct, AuthCode du dernier trailer physique
et la politique d’intégrité obligatoire conservent leurs contrôles. Choisir
`SourceIntegrityPolicy::RequirePdfMac` lorsqu’une intégrité est attendue ; voir le
[guide MAC source](source-mac.md). Ni le déchiffrement CMS ni un MAC valide n’établit
la confiance d’une signature, la révocation ou l’autorisation de modifier des révisions protégées.

## Limites et validation

`PublicKeyAuthenticationLimits` permet par défaut 64 filtres, 1 024 paquets de
destinataires au total, 1 Mio par paquet, 16 Mio cumulés, 4 096 tentatives de
credentials et 32 identités applicatives. Les comptes, octets et tentatives sont
cumulatifs entre filtres, accès différés compris, et repartent à zéro pour chaque
lecture. Les limites générales de syntaxe, source, rétention et décodage s’appliquent
aussi. `PublicKeyAuthenticationFailed` signale l’absence de credential correspondant
sans exposer certificat, clé ou données source. Dictionnaires/payloads mal formés
et budgets épuisés produisent `ParseException`.

L’oracle indépendant `tools/validate_public_key_authentication.py` génère des sources
réelles avec pyHanko non modifié et compare les résultats PHP à des opérations
CMS/clé/chiffrement indépendantes. Ses 47 sources comprennent 14 cas s3 ; dix
combinent chaque droit R2 avec les bits source 9–12 nuls ou actifs. pyHanko fournit
les vrais CMS et octets chiffrés ; l’oracle applique séparément l’interprétation
normative s3, sans reprendre l’énumération commune des droits de pyHanko.
Les tests adverses natifs couvrent séparément
l’ordre, la portée, les premiers droits correspondants, les filtres inconnus,
les échecs différés et les limites. Les oracles Standard, metadata, hybrides et MAC
existants restent requis.

Sources normatives conservées sans modification dans le core : ISO 32000-2:2020
§§7.6.5–7.6.6 (pages physiques 101–108), ISO 32000-1:2008 §7.6.4 (pages 72–77),
supplément Adobe AESV3 (page 21), ISO/TS 32004:2024 §6.4 (page 15). [La carte des sources](../../resources/public-key-authentication.json)
consigne leurs SHA-256 ; l’implémentation réutilise les primitives de dérivation et
de chiffrement documentées du core au lieu d’ajouter une implémentation cryptographique.
