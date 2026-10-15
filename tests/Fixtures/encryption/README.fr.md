# Fixtures indépendantes de chiffrement par mot de passe

[English](README.md) | Français

Ces six PDF originaux sous licence MIT ont été chiffrés par qpdf 12.4.2 puis
copiés sans modification depuis les fixtures `tests/Fixtures/adobe-encryption`
du core. Les mots de passe publics de test sont `user` et `owner`.
`manifest.json` consigne leurs tailles et leurs empreintes SHA-256. Les quatre
premiers exercent les révisions ISO 2/3/4 ; les deux autres, la révision Adobe 5.

La CI utilise aussi `tools/validate_authentication.py` pour créer 20 autres PDF
chiffrés indépendamment avec qpdf : RC4/AES, R2 à R6, accès utilisateur et
propriétaire, exemptions des métadonnées et des signatures, objets comprimés.
Le lecteur natif doit retrouver exactement les chaînes binaires, contenus de
pages et XMP d'origine. Ces identifiants publics ne sont pas des secrets
d'application. Les anciens algorithmes servent aux tests de compatibilité ;
ils ne sont jamais choisis implicitement pour un nouveau chiffrement.
