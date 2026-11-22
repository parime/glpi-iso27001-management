# Issue #618 : delegation des decisions pendant une absence (jeu de donnees : setup.d/618-delegation.php).
# iso-sans-droit (profil none) est le suppleant de iso-titulaire-absent (absence en cours) et de
# iso-titulaire-revenu (absence terminee hier), pour la revue des acces seulement. Il ne voit et ne
# decide que les comptes confies au titulaire absent, jamais le sien, jamais ceux d'un autre
# responsable, et plus rien apres la fin de l'absence. Aucune decision n'est enregistree ici : les
# refus ne changent rien (le nombre de lignes est compare avant et apres la matrice).
readable front/delegation.php

mine=$(curl -s -c "$JAR" -b "$JAR" "$P/accessreview.mine.php")
substitute=non; [ "$ROLE" = none ] && substitute=oui
contains "Mes revues d'acces : campagne du titulaire absent" "Revue deleguee securite" $substitute "$mine"
contains "Mes revues d'acces : compte confie au titulaire absent" "iso-compte-delegue" $substitute "$mine"
contains "Mes revues d'acces : mention pour le compte de" "iso-titulaire-absent" $substitute "$mine"
contains "Mes revues d'acces : absence terminee, plus rien" "iso-compte-revenu" non "$mine"
contains "Mes revues d'acces : compte d'un autre responsable" "iso-compte-autre" non "$mine"
contains "Mon espace GRC : absences en cours" 'id="grc-myspace-delegations"' $substitute \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/myspace.php")"

expect "Revue deleguee : decision sans jeton CSRF" 403 \
  "$(code -d "save_decisions=1&decisions[$(id delegation_line_ok)]=keep" "$P/accessreview.mine.php")"

if [ "$ROLE" = none ]; then
  refused() { # libelle, ligne
    expect "$1 : decision refusee" 302 \
      "$(code -d "save_decisions=1&decisions[$2]=keep&_glpi_csrf_token=$(csrf)" "$P/accessreview.mine.php")"
    contains "$1 : message de refus" "This line is assigned to another reviewer." oui \
      "$(curl -s -c "$JAR" -b "$JAR" "$P/accessreview.mine.php")"
  }
  refused "Revue deleguee : apres la fin de l'absence" "$(id delegation_line_expired)"
  refused "Revue deleguee : son propre compte" "$(id delegation_line_own)"
  refused "Revue deleguee : compte d'un autre responsable" "$(id delegation_line_other)"
  # Type non delegable (approbation des politiques et de la SoA) : aucune reponse pour le titulaire.
  expect "Approbation d'une politique pour le titulaire : refusee" 302 \
    "$(code -d "answer=1&decision=approve&versions_id=1&on_behalf_of=$(id delegation_holder)&_glpi_csrf_token=$(csrf)" "$P/policyapproval.php")"
  contains "Approbation d'une politique pour le titulaire : message de refus" \
    "This version is not awaiting your answer." oui "$(curl -s -c "$JAR" -b "$JAR" "$P/myspace.php")"
  expect "front/soaapproval.php : type non delegable, page fermee" 403 "$(code "$P/soaapproval.php")"
  expect "front/policyapproval.php : type non delegable, page fermee" 403 "$(code "$P/policyapproval.php")"
fi

# Reglage des types delegables : droit de modifier le plugin et la configuration de GLPI.
expect "Reglage de la delegation sans jeton CSRF" 403 \
  "$(code -d "update_delegation_config=1&delegation_types[]=soa_approval" "$P/delegation.php")"
case $ROLE in
  none|reader|limited|general)
    expect "Reglage de la delegation sans le droit de configuration" 403 \
      "$(code -d "update_delegation_config=1&delegation_types[]=soa_approval&_glpi_csrf_token=$(csrf)" "$P/delegation.php")" ;;
esac
see_config=oui; [ "$ROLE" = none ] && see_config=non
contains "front/config.php : encadre de la delegation" 'id="grc-delegation-config"' $see_config \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php")"
