### Added

- **Matrice des risques de 3 à 5 niveaux sur chaque axe, indépendamment** (issue #1163, clause 6.1.2).
  Configuration > Plugins > GRC Manager, onglet Risques, bloc « Taille de la matrice » : en plus de
  4 x 4 et 5 x 5, on choisit 3 x 3, 3 x 5 (3 niveaux de probabilité, 5 d'impact), 5 x 3, 3 x 4,
  4 x 3, 4 x 5 ou 5 x 4. Le changement passe par l'écran de correspondance existant (aperçu de
  l'effet sur les risques, historique de chaque risque converti). Les couleurs de la nouvelle grille
  sont proposées d'après le score (seuils au quart, à la moitié et aux trois quarts du score
  maximal) et restent modifiables ; les libellés des niveaux se renomment comme avant (par exemple
  Faible, Moyen, Élevé pour la probabilité). Une matrice 4 x 4 ou 5 x 5 existante ne change pas.
  La carte des risques, l'import CSV, EBIOS RM et l'AIPD suivent la forme choisie.
- **Score avant et après traitement** (issue #1163, clause 6.1.3, risque résiduel). La fiche du
  risque montre côte à côte le score initial et le score résiduel (sur le score maximal de la
  matrice), avec leurs niveaux ; la liste des risques propose deux colonnes « Score initial » et
  « Score résiduel ». Le score résiduel est calculé et enregistré avec le niveau résiduel ; les
  risques déjà évalués sont remplis à la mise à jour.

### Fixed

- Le risque créé depuis l'analyse des chemins d'attaque Active Directory reçoit de nouveau une
  probabilité et un impact de l'échelle configurée (au lieu des valeurs par défaut).
