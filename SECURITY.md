# Politique de sécurité

Le lecteur reçoit des documents externes. Les contrôles et limites actuels
sont décrits dans [docs/security.md](docs/security.md). Ils doivent être
complétés par les contrôles d'accès, quotas et budgets de l'application.

## Versions prises en charge

La série `0.1.x` est prise en charge. Les corrections de sécurité sont publiées
dans cette série. PHP 8.3 minimum est requis ; PHP 8.3, 8.4 et 8.5 sont vérifiés
en CI. Utiliser une version PHP maintenue avec ses correctifs.

## Signaler une vulnérabilité

Utiliser le [signalement privé GitHub](https://github.com/PDFToolkit/pdf-reader/security/advisories/new)
lorsque disponible. Ne pas publier une charge exploitable, un document
confidentiel ou des identifiants dans une issue publique.

Si le signalement privé est indisponible, ouvrir une issue demandant un canal
confidentiel, sans révéler les détails d'exploitation.

Un rapport utile précise :

- La révision du lecteur et celle de `roukmoute/pdf` utilisées.
- La version PHP, les extensions concernées et les `ReaderLimits` configurés.
- La surface touchée, le comportement observé et l'impact attendu.
- Une reproduction minimale, idéalement avec un PDF synthétique sans donnée personnelle.

Les mécanismes explicitement non pris en charge sont signalés par
`UnsupportedPdfFeature`. Un dépassement de budget attendu est signalé par
`ParseException`. Un contournement de ces contrôles, une consommation non bornée
ou une confusion dans la résolution des objets peut constituer un problème
de sécurité et mérite un signalement.

Le modèle et le writer ont leur propre
[politique de sécurité](https://github.com/PDFToolkit/pdf/blob/master/SECURITY.md).
