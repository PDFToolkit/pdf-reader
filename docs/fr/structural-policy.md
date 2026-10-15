# Politique structurelle explicite

[English](../structural-policy.md) | Français

`new DocumentReader(structuralPolicy: StructuralPolicy::Pdf20)` active des contrôles
structurels ciblés ISO 32000-2 EC3. `StructuralPolicy::Compatible` reste le défaut
et préserve la tolérance de lecture historique. Choisir `Pdf20` applique ces
contrôles à la source fournie, même si son header déclare une version antérieure ;
cela ne modifie pas sa version et n’atteste pas une conformité PDF complète.

La politique suit les lectures ordinaires, graphes authentifiés, flux d’objets
et vues physiques par révision. `requiringSourceIntegrity()` la conserve ; la
validation structurelle et la politique d’authentification/MAC restent distinctes.
Les utilisateurs directs de `SyntaxParser` et des lecteurs structurels peuvent
fournir cette même politique.

L’issue EC3 #379 (pages physiques sources 48 et 1020) exige des identifiants
d’objet/génération décimaux canoniques. En mode `Pdf20`, headers d’objets,
références indirectes et numéros des membres de flux d’objets refusent les zéros
initiaux ; une génération peut être le chiffre unique `0`. Les objets numériques
généraux et les champs xref traditionnels de largeur fixe gardent leur syntaxe.
Un signe plus initial n’est pas un identifiant d’objet.

La politique n’ajoute ni I/O, réparation, confiance PKI ni autorité de modification.
Les limites de ressources s’appliquent toujours. Voir les
[historiques physiques](revision-history.md).
