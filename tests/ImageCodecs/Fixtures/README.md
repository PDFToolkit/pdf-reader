# Reader image-adapter fixtures / Fixtures d’adaptation image

English / Français

These small generated images exercise the Reader adapter, not a renderer or user documents. `manifest.json` lists input hashes, reference files, native profile decisions and upstream fixture paths. The snapshot identifies the original `pdf-codecs` manifests by SHA-256; it does not invent an unreleased source revision.

Ces petites images générées contrôlent l’adaptateur Reader, sans document utilisateur. `manifest.json` indique les hashes d’entrée, références, décisions de profil natif et chemins des fixtures d’origine. Les manifestes `pdf-codecs` d’origine sont identifiés par SHA-256, sans inventer une révision encore non publiée.

- JPEG: `rgb.jpg` and `cmyk-progressive.jpg` come from `pdftoolkit/pdf-codecs`'s generated 37×29 JPEG corpus (`tools/jpeg_generate.py`, Pillow/IJG). The second is progressive CMYK with restart markers. `rgb-component-identifiers.jpg` removes APP0 and renames matching SOF/SOS component identifiers to R/G/B; its entropy data is unchanged. It verifies the PDF default instead of generic JPEG colour heuristics.
- JPEG : les deux premiers fichiers viennent du corpus 37×29 généré par `tools/jpeg_generate.py` de `pdftoolkit/pdf-codecs` (Pillow/IJG). Le second est CMYK progressif avec redémarrages. Le troisième retire APP0 et renomme les identifiants SOF/SOS concordants en R/G/B, sans changer les données entropiques, afin de contrôler le défaut couleur PDF.
- JPEG 2000: `pdftoolkit/pdf-codecs/tools/jpx_generate.py` and its OpenJPEG 2.5.4 oracle generate the inputs and exact JSON component references. The source manifest identifies each evidence category. The generated Gray ICC profile is preserved exactly; its hash is checked separately. These files include signed/nonstandard precision, origin, palette, channel alpha, alternative colour specifications and the JPX chroma-key metadata ignored by PDF.
- JPEG 2000 : le générateur et l’oracle OpenJPEG 2.5.4 de `pdf-codecs` produisent les entrées et références JSON exactes. Le manifeste distingue les catégories de preuves. Le profil ICC Gray généré est conservé exactement, avec contrôle séparé de son hash. Les cas couvrent signe/précision particulière, origine, palette, alpha de canal, spécifications couleur alternatives et métadonnée de clé chromatique JPX ignorée par PDF.
- JBIG2: these are original synthetic vectors from `pdftoolkit/pdf-codecs/tools/generate_jbig2.py`, with explicit expected bitmaps. Ordinary vectors were independently decoded by libjbig2dec; refinement cases were also checked with Poppler. The 12-adaptive-template, retained-context and HDEFPIXEL=1 cases are declared normative vectors because libjbig2dec 0.20 is incomplete/incorrect there. They verify early PHP selection, not native conformance. No ITU Annex H document or user bitmap is copied here.
- JBIG2 : vecteurs synthétiques originaux du générateur `pdf-codecs`, avec bitmaps attendus explicites. Les cas ordinaires ont été décodés indépendamment par libjbig2dec ; les raffinements ont aussi été contrôlés par Poppler. Les douze gabarits adaptatifs, contextes conservés et HDEFPIXEL=1 sont des vecteurs normatifs déclarés car libjbig2dec 0.20 est incomplet/incorrect sur ces points. Ils contrôlent le repli PHP précoce, sans prétendre à la conformité native. Aucun document ITU Annex H ni bitmap utilisateur n’est copié ici.

`text-globals.jb2` and `.globals` split the synthetic text fixture's symbol dictionary into page zero without changing entropy data; terminal end-of-page records are removed. `tools/build_image_codec_fixtures.py` builds `globals.pdf` with pypdf and encrypts the same graph with qpdf AES-256 R6. Globals are separately Flate encoded. The public test credentials are `user` / `owner`. `globals-pdf.json` records hashes; regenerated encryption uses fresh random salts/IVs and therefore needs updated hashes. These are test credentials, not user secrets.

`text-globals.jb2` et `.globals` séparent le dictionnaire de symboles synthétique en page zéro sans changer les données entropiques ; les segments de fin de page sont retirés. L’outil construit `globals.pdf` avec pypdf puis chiffre le même graphe en AES-256 R6 avec qpdf. Les globals sont encodés séparément en Flate. Les identifiants publics de test sont `user` / `owner`. Le JSON fixe les hashes ; une nouvelle génération du chiffrement crée des sels/IV aléatoires et exige leur mise à jour. Ce ne sont pas des secrets utilisateur.

Replay / Rejouer :

```console
python tools/validate_image_codecs.py --php php --require-native --report _build/image-codecs.json
```

The oracle compares actual decoded planes, ICC/channel metadata, native selection, pypdf stream/decryption binding, qpdf structure, and live Pillow/Poppler results. JPEG rounding tolerances are explicit. No fixture count proves universal codec conformance.

L’oracle compare les plans décodés, métadonnées ICC/canaux, sélection native, liaison et déchiffrement des flux par pypdf, structure qpdf et résultats réels Pillow/Poppler. Les tolérances JPEG sont explicites. Aucun nombre de fixtures ne prouve une conformité universelle.

The no-end-of-page globals case declares `nativeMinimumRevision=10030`: the oracle requires exact native samples with Ghostscript ≥10.03.0, and explicit native unavailability plus exact PHP fallback on older versions. / Le cas globals sans fin de page fixe `nativeMinimumRevision=10030` : l’oracle exige les échantillons natifs exacts avec Ghostscript ≥10.03.0, et une indisponibilité native explicite avec repli PHP exact sur les versions antérieures.
