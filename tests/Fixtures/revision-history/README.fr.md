# Fixtures de révisions physiques

[English](README.md) | Français

`trailing-xref.pdf` est la sortie déterministe non signée de
`trailing_objects_initial()` dans [l’oracle indépendant](../../../tools/validate_revision_history.py).
Son producteur d’octets Python n’utilise aucune implémentation PHP. Catalogue et
pages précèdent le flux xref ; trois objets ordinaires indexés le suivent, dont
un flux dont la longueur indirecte est stockée après lui. De faux marqueurs de
footer/endobj restent dans le contenu délimité par sa longueur.

L’oracle compare les octets committés à ce producteur, les contrôle avec qpdf et
pyHanko, puis crée une véritable signature CMS et une mise à jour ultérieure avec
les API pyHanko non modifiées. Les clés éphémères des certificats et les variantes
signées restent dans le répertoire de validation choisi et ne sont pas committées.
