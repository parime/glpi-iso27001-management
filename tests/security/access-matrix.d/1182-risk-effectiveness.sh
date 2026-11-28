# shellcheck shell=bash
# Issue #1182 : revues datees des risques (onglet « Revues »), risque cible et efficacite du traitement,
# acceptation signee (jamais par le proprietaire du risque), profils prets a attribuer (configuration).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables). Aucune donnee
# n'est enregistree ici : seuls des envois refuses sont tentes.
see_risks=non; case $ROLE in reader|limited|admin) see_risks=oui ;; esac
review_tab() { tab 'GlpiPlugin%5CGrcmanager%5CRisk' 'GlpiPlugin%5CGrcmanager%5CRiskReview$1' "$1"; }
contains "onglet Revues du risque de A : revue enregistree" "Revue securite A" $see_risks \
  "$(review_tab "$(id effectiveness_risk_A)")"
contains "onglet Revues du risque de B (autre entite)" "Revue securite B" $SEE_B \
  "$(review_tab "$(id effectiveness_risk_B)")"
contains "onglet Revues du risque de A : formulaire de revue" 'id="grc-risk-review-form"' \
  "$(case $ROLE in limited|admin) echo oui ;; *) echo non ;; esac)" "$(review_tab "$(id effectiveness_risk_A)")"

# Enregistrer une revue : droit de modifier le risque, dans son entite, avec le jeton CSRF.
review_post() { # id du risque, jeton
  code -d "add=1&plugin_grcmanager_risks_id=$1&comment=Revue%20refusee%20securite&_glpi_csrf_token=$2" \
    "$P/riskreview.form.php"
}
case $ROLE in
  none|reader|general)
    expect "front/riskreview.form.php : revue du risque de A sans droit de modifier" 403 \
      "$(review_post "$(id effectiveness_risk_A)" "$(csrf)")" ;;
esac
[ "$ROLE" != admin ] && expect "front/riskreview.form.php : revue du risque de B (autre entite)" 403 \
  "$(review_post "$(id effectiveness_risk_B)" "$(csrf)")"
expect "front/riskreview.form.php : revue sans jeton CSRF" 403 \
  "$(code -d "add=1&plugin_grcmanager_risks_id=$(id effectiveness_risk_A)&comment=x" "$P/riskreview.form.php")"
case $ROLE in
  none|general) expect "front/riskreview.form.php : consultation sans droit" 403 "$(code "$P/riskreview.form.php")" ;;
esac

# Efficacite du traitement : plan termine, residuel au-dessus de la cible (fiche du risque de A).
case $ROLE in
  reader|limited|admin)
    contains "fiche du risque de A : alerte d'efficacite du traitement" 'id="grc-risk-effectiveness"' oui \
      "$(tab 'GlpiPlugin%5CGrcmanager%5CRisk' 'GlpiPlugin%5CGrcmanager%5CRisk$main' "$(id effectiveness_risk_A)")" ;;
esac

# Separation des taches : le gestionnaire limite a A est proprietaire du risque ; sa demande de
# signature est refusee cote serveur (la fiche reste « non signee »).
case $ROLE in
  limited)
    code -d "update=1&id=$(id acceptance_risk_owned)&sign_acceptance=1&acceptance_expiry=2099-01-01&_glpi_csrf_token=$(csrf)" \
      "$P/risk.form.php" > /dev/null
    owned=$(tab 'GlpiPlugin%5CGrcmanager%5CRisk' 'GlpiPlugin%5CGrcmanager%5CRisk$main' "$(id acceptance_risk_owned)")
    contains "acceptation du risque dont on est proprietaire : jamais signee" 'data-grc-acceptance="unsigned"' oui "$owned"
    contains "acceptation du risque dont on est proprietaire : case de signature absente" 'name="sign_acceptance"' non "$owned" ;;
esac

# Profils prets a attribuer : creation reservee a qui peut creer des profils (droit du coeur).
case $ROLE in
  none|reader|limited|general)
    expect "front/config.php : creer un profil de gestion des risques sans droit" 403 \
      "$(code -d "create_risk_profile=internal_audit&_glpi_csrf_token=$(csrf)" "$P/config.php")" ;;
esac
expect "front/config.php : creer un profil de gestion des risques sans jeton CSRF" 403 \
  "$(code -d "create_risk_profile=internal_audit" "$P/config.php")"
case $ROLE in
  reader|admin)
    page=$(body "$P/config.php?tab=risks")
    contains "configuration, onglet risks : profils prets a attribuer" 'id="grc-risk-profiles-config"' oui "$page"
    contains "configuration : bouton de creation des profils" 'name="create_risk_profile"' $IS_ADMIN "$page" ;;
esac
