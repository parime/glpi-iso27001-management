# shellcheck shell=bash
# Issue #1083 : plans de continuite et de reprise (Assistance > Crise et continuite). Droit de la
# continuite et entite du plan : liste et fiche refusees sans ce droit (profils sans droit et sans
# registres sensibles), lecture seule pour le lecteur, modification par le gestionnaire de A ; un plan
# de B n'est ni nomme ni ouvert hors super-admin. Le circuit d'approbation et les tests exigent de
# pouvoir modifier le plan ; repondre exige d'etre approbateur de la version en attente.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/continuityplan.php
cp_tab() { # onglet, id du plan
  tab GlpiPlugin%5CGrcmanager%5CContinuityPlan "GlpiPlugin%5CGrcmanager%5C$1" "$2"
}
cp_post() { # parametres
  code -d "$1&_glpi_csrf_token=$(csrf)" "$P/continuityplan.form.php"
}
case $ROLE in
  none|general)
    expect "plan de continuite de A : sans le droit de la continuite" 403       "$(code "$P/continuityplan.form.php?id=$(id continuityplan_A)")"
    expect "plan de continuite de A en PDF : sans le droit de la continuite" 403       "$(code "$P/continuityplan.export.php?id=$(id continuityplan_A)")"
    expect "modifier un plan de A : sans le droit de la continuite" 403       "$(cp_post "update=1&id=$(id continuityplan_A)&comment=securite")"
    contains "onglet Plans de continuite d'un processus : rien" "Plan de reprise securite" non       "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CContinuityPlan$1' "$(id process_A)")"
    ;;
  reader|limited)
    expect "plan de continuite de A" 200 "$(code "$P/continuityplan.form.php?id=$(id continuityplan_A)")"
    expect "plan de continuite de B" 403 "$(code "$P/continuityplan.form.php?id=$(id continuityplan_B)")"
    expect "plan de continuite de A en PDF" 200 "$(code "$P/continuityplan.export.php?id=$(id continuityplan_A)")"
    expect "plan de continuite de B en PDF" 403 "$(code "$P/continuityplan.export.php?id=$(id continuityplan_B)")"
    plans=$(body "$P/continuityplan.php")
    contains "liste des plans : plan de A" "Plan de reprise securite A" oui "$plans"
    contains "liste des plans : plan de B" "Plan de reprise securite B" non "$plans"
    contains "onglet Plans de continuite du processus A" "Plan de reprise securite A" oui       "$(tab GlpiPlugin%5CGrcmanager%5CBusinessProcess 'GlpiPlugin%5CGrcmanager%5CContinuityPlan$1' "$(id process_A)")"
    contains "onglet Tests du plan de A" "Delai depasse" oui "$(cp_tab 'ContinuityPlanTesting$1' "$(id continuityplan_A)")"
    contains "onglet Tests du plan de B" "Delai depasse" non "$(cp_tab 'ContinuityPlanTesting$1' "$(id continuityplan_B)")"
    expect "modifier un plan de B" 403 "$(cp_post "update=1&id=$(id continuityplan_B)&comment=securite")"
    expect "enregistrer un test sur un plan de B" 403 "$(cp_post "add_test=1&id=$(id continuityplan_B)&test_date=2026-01-01")"
    expect "repondre sans etre approbateur" 403 "$(cp_post "answer_approval=1&decision=approve&id=$(id continuityplan_A)")"
    if [ "$ROLE" = reader ]; then
      expect "modifier un plan de A en lecture seule" 403 "$(cp_post "update=1&id=$(id continuityplan_A)&comment=securite")"
      expect "soumettre un plan de A en lecture seule" 403 "$(cp_post "submit_approval=1&id=$(id continuityplan_A)")"
      expect "enregistrer un test en lecture seule" 403 "$(cp_post "add_test=1&id=$(id continuityplan_A)&test_date=2026-01-01")"
      expect "creer un plan en lecture seule" 403 "$(cp_post "add=1&name=securite&plan_type=pca")"
    else
      expect "modifier un plan de A (gestionnaire de A)" 302 "$(cp_post "update=1&id=$(id continuityplan_A)&comment=securite")"
    fi
    ;;
  admin)
    expect "plan de continuite de A" 200 "$(code "$P/continuityplan.form.php?id=$(id continuityplan_A)")"
    expect "plan de continuite de B" 200 "$(code "$P/continuityplan.form.php?id=$(id continuityplan_B)")"
    contains "liste des plans : plan de B" "Plan de reprise securite B" oui "$(body "$P/continuityplan.php")"
    contains "front/crisiscontinuity.php : carte vers les plans" "front/continuityplan.php" oui       "$(body "$P/crisiscontinuity.php")"
    contains "configuration, Incidents et continuite : reglages des plans" 'id="grc-continuity-plan-settings"' oui       "$(body "$P/config.php?tab=incidents")"
    contains "onglet Versions et approbation du plan de A : demande d'approbation" 'name="submit_approval"' oui       "$(cp_tab 'ContinuityPlanVersion$1' "$(id continuityplan_A)")"
    expect "modifier un plan sans jeton CSRF" 403       "$(code -d "update=1&id=$(id continuityplan_A)&comment=securite" "$P/continuityplan.form.php")"
    expect "repondre sans etre approbateur (super-admin)" 403       "$(cp_post "answer_approval=1&decision=approve&id=$(id continuityplan_A)")"
    ;;
esac
