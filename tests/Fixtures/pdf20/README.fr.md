# Fixtures de syntaxe PDF 2.0

[English](README.md) | Français

Fixtures originales MIT créées par `tools/build_pdf20_fixture.py` avec pypdf
6.20.1 et qpdf 12.4.2, indépendamment du writer PHP. Le manifeste consigne
leurs tailles, identités SHA-256 et versions attendues du header et du catalogue.

`header.pdf` possède un header PDF 2.0 ; `catalog.pdf` un header PDF 1.7 et
un catalogue PDF 2.0 ; `object-streams.pdf` utilise les flux de références et
d’objets PDF 2.0. Chaque fichier contient une page blanche et une chaîne du
catalogue avec BOM UTF-8. Les tests l’interprètent explicitement avec le décodeur
PDF 2.0 du core ; le lecteur générique conserve ses octets. Les vérifications
indépendantes emploient pypdf et qpdf.
