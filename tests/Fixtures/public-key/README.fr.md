# Fixtures indépendantes d’authentification par clé publique

[English](README.md) · [Français](README.fr.md)

`tools/validate_public_key_authentication.py` produit des PDF Adobe.PubSec
synthétiques avec pyHanko 0.47.0 et cryptography 50.0.2 verrouillés, puis les lit
avec le lecteur PHP natif. Installer `tools/source-mac-validation-requirements.txt`
avec `pip --require-hashes` ; ces bibliothèques servent uniquement aux oracles
de développement.

```sh
python tools/validate_public_key_authentication.py --php php
```

Utiliser `--work-dir _build/public-key` pour conserver PDF, manifeste SHA-256 et
credentials de test générés. Utiliser `--fixtures-dir _build/public-key` pour
revalider les mêmes octets. Le producteur autonome est
`tools/generate_public_key_fixtures.py`. Les certificats sont des identités de test
autosignées ; aucune confiance PKI, validation de signature ou opération réseau
n’est revendiquée. Clés, seeds, IV et horodatages aléatoires changent les octets
entre exécutions ; les cas et assertions restent reproductibles.

Les 47 cas positifs couvrent s3/s4 RC4, s5 RC4/AESV2/AESV3/AESV4, les deux états
du bit de permission 2, un premier paquet CMS non correspondant suivi d’un
second correspondant, flux d’objets, xref hybride, révision incrémentale,
métadonnées claires du catalogue, clés document/EFOpen distinctes avec filtres
Crypt explicites, Identity et MAC standalone/attachés à une signature. L’oracle
déchiffre indépendamment les enveloppes CMS RSA/AES avec cryptography, vérifie
les permissions big-endian et la dérivation de clé sur le tableau de
destinataires, compare chaînes/flux/métadonnées clairs et vérifie empreintes MAC,
HKDF, encapsulation de clé et HMAC. Il exige aussi 47 refus de mauvaise identité,
cinq refus de MAC altérés et le refus d’un MAC authentifié avec la mauvaise clé
de filtre.

Dix sources S3 supplémentaires combinent chaque permission R2 avec les bits
source 9–12 nuls ou actifs. Le masque PHP doit dériver les droits granulaires des
seuls bits R2 3–6 et conserver séparément le mot CMS original. pyHanko vérifie CMS
et chiffrement ; son énumération commune ne valide pas l’interprétation S3.

Un cas MAC utilise volontairement une clé DefaultCryptFilter différente de
StmF/StrF. L’accesseur MAC global de pyHanko choisit StmF : ce cas utilise donc ses
composants MAC/CMS bas niveau inchangés avec la clé DefaultCryptFilter explicite
et l’oracle cryptography indépendant. Le contrôle inverse est accepté par le
validateur global de pyHanko avec la clé StmF et doit être refusé par le lecteur
PHP. L’entrée hybride est analysée en mode non strict par pyHanko, son mode strict
refusant volontairement les xref hybrides. Aucune dépendance n’est modifiée.

Ces sources vérifient l’authentification et la lecture bornée, pas la confiance
PKI, la conformité PDF complète ni le rendu graphique. L’option ISO de permissions little-endian est couverte par les tests unitaires
natifs, et non par ces fixtures CMS indépendantes.
Les clés privées de test ne doivent jamais protéger des données applicatives.

Un second oracle, `tools/validate_public_key_aliases.py`, couvre quatre cas de
métadonnées AES-128/256 avec références directes et alias Encrypt/Root/Metadata
sur trois sauts. Il compare le parseur strict pyHanko inchangé et cryptography au
lecteur PHP, y compris les métadonnées page/Form chiffrées et les CMS/ciphertexts
originaux conservés. Deux cas ajoutent une pièce jointe EFF implicite avec une clé
distincte AES-128 ou AES-256. La dérivation par numéro d’objet et les échecs différés
d’identité manquante sont vérifiés indépendamment, sans filtre Crypt explicite.

```sh
python tools/validate_public_key_aliases.py --php php
```
