# Données de flux externes

[English](../external-streams.md) | Français

Un flux externe utilise `F` pour désigner une spécification de fichier. Ses octets
locaux sont ignorés pour le décodage ; `FFilter` et `FDecodeParms` décrivent les octets
fournis. `EncodedStream` conserve les octets locaux pour inspection. Ces règles
proviennent d’ISO 32000-2:2020, tableau 5 (pages physiques 47–48 du PDF EC3 conservé).

Fournir explicitement un `ExternalStreamSource` à `DocumentReader` ou `StreamDecoder`.
Sans cette capacité, le décodage externe lève `UnsupportedPdfFeature`. Analyser le
catalogue ou inspecter les révisions physiques n’acquiert pas les données externes
ordinaires. L’analyse xref ne reçoit jamais cette capacité. Aucun fichier, URL,
pièce jointe ou nom dépendant de la plateforme n’est ouvert automatiquement.

```php
use PDF\Reading\DocumentReader;
use PDF\Reading\ExternalStreamRequest;
use PDF\Reading\ExternalStreamSource;
use PDF\Reading\FileBytes;
use PDF\Syntax\Object\StringType;

final readonly class PublishedAssets implements ExternalStreamSource
{
    /** @param array<string, FileBytes> $assets Déjà acquis par l’application. */
    public function __construct(private array $assets) {}

    public function read(ExternalStreamRequest $request): FileBytes
    {
        // La politique de cette application emploie les octets F exacts des ressources connues.
        $name = $request->specification instanceof StringType
            ? $request->specification : $request->field('F');
        $bytes = $name instanceof StringType ? ($this->assets[$name->value] ?? null) : null;
        if ($bytes === null || strlen($bytes->bytes) > $request->maximumBytes) {
            throw new RuntimeException('Aucune ressource bornée fournie pour cette spécification.');
        }
        return $bytes;
    }
}

$reader = new DocumentReader(externalStreams: new PublishedAssets([
    'content.bin' => new FileBytes($alreadyAcquiredEncodedBytes),
]));
$document = $reader->read(new FileBytes($pdfBytes));
// $document->streamData($stream) acquiert ce flux au premier accès décodé seulement.
```

`ExternalStreamRequest::specification` contient la chaîne ou le dictionnaire résolu ;
`field($name)` résout un champ avec le résolveur et les bornes du document propriétaire.
L’application choisit le sens de `F`, `UF`, `FS`, des noms de plateforme, des entrées
embarquées et des ressources absentes. Leur présence ne vaut pas autorisation d’accès.
L’exemple définit une petite politique de ressources déjà présentes en mémoire.

`ReaderLimits::maxExternalStreamBytes` borne chaque acquisition (64 Mio par défaut).
La source impose `maximumBytes` pendant l’acquisition ; la bibliothèque contrôle
à nouveau la taille retournée. `maxExternalStreamRequests` borne les tentatives
par document (64 par défaut), échecs compris. Les octets retournés comptent dans le
budget de rétention ; chaque décodage conserve les limites par flux et cumulées.
Les accès répétés par `ReadDocument::streamData()` utilisent son cache local au
document. Chaque appel autonome à `StreamDecoder` acquiert les données et partage
le budget fourni.

L’authentification PDF authentifie la source PDF et ses permissions. Un MAC source
PDF n’authentifie pas les octets du fichier externe. `requiringSourceIntegrity()`
conserve la capacité explicitement fournie sans modifier cette limite.

## Validation indépendante

`tools/validate_external_streams.py` crée huit PDF avec pyHanko non modifié :
spécifications chaîne/dictionnaire/indirectes et filtres en cascade, chacune en clair
ou chiffrée AES-256. Il applique les codecs indépendants de pypdf aux véritables
entrées de filtres externes, puis compare tous les octets décodés avec Reader.
qpdf contrôle chaque PDF sans récupération. L’oracle vérifie les octets locaux
conservés, l’acquisition différée, le cache et l’authentification distincte du document.
Les tests natifs couvrent aussi les formes invalides, l’absence de capacité, les
références nulles, la résolution des paramètres et les plafonds partagés.

[Composants](components.md) · [Sécurité](security.md) · [MAC source](source-mac.md)
