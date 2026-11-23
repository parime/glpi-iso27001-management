# shellcheck shell=bash
# Issue #1038 : menu GRC en rubriques aux noms parlants, avec une page d'accueil par rubrique
# (front/section.php). Chaque entree, sous-page et page propre de rubrique est deja verifiee par
# 1037-menu-sections.sh (200 et fil d'Ariane, refus propre sans droit) ; ici : la page d'accueil,
# les ecrans autrefois hors menu qu'elle montre, et une rubrique inconnue redirigee (jamais de 404).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable "front/section.php?section=risk"
expect "front/section.php : rubrique inconnue redirigee vers le tableau de bord" 302 \
  "$(code "$P/section.php?section=inconnue")"
if [ "$ROLE" = admin ]; then
  hub_risk=$(curl -s -c "$JAR" -b "$JAR" "$P/section.php?section=risk")
  contains "Risques, accueil : ce qui vous attend" 'id="grc-section-todo"' oui "$hub_risk"
  contains "Risques, accueil : carte thermique (autrefois hors menu)" "front/riskheatmap.php" oui "$hub_risk"
  contains "Risques, accueil : catalogue (autrefois hors menu)" "front/riskcatalog.php" oui "$hub_risk"
  contains "Risques, accueil : import (autrefois dans Outils)" "front/riskimport.php" oui "$hub_risk"
  contains "Organisation du SMSI, accueil : decisions deleguees (autrefois sans menu)" "front/delegation.php" oui \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/section.php?section=ismsorganisation")"
  # Decisions deleguees : plus de bouton dans la configuration, le menu y mene.
  contains "front/config.php : plus de bouton vers les decisions deleguees" 'front/delegation.php"><i class="ti ti-list-details' non \
    "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php?tab=people")"
fi
