# Corpus CCITT synthétique

[English](README.md)

Ces motifs de test sous licence MIT ne contiennent aucun document externe ni donnée personnelle. `tools/build_fax_fixtures.py` crée les PDF avec pypdf. Son mode `--refresh` reconstruit volontairement les bandes TIFF compressées indépendamment par Pillow/libtiff ; `--check` vérifie les octets PDF contre le manifeste conservé sans régénérer la compression.

`manifest.json` contient la version de l'encodeur, les empreintes exactes des flux encodés et les bitmaps attendus explicites. Quatorze cas utilisent libtiff. Vingt-six cas de l'extension non compressée proviennent directement des tables UIT T.4/T.6 et possèdent des pixels attendus explicites indépendants de l'implémentation PHP. Ils couvrent toutes les longueurs/couleurs de sortie et les motifs traversant des lignes T.6. Voir [les preuves et leurs limites](../../../docs/fr/fax.md) ; rendre un PDF décodé ne constitue pas un oracle indépendant du décodage de l'extension.
