# shellcheck shell=bash
# Issue #881 : preparer l'audit du cycle suivant exige de lire l'audit d'origine et de pouvoir creer
# un audit dans son entite, avec jeton CSRF ; jamais depuis un audit de B pour un profil limite a A.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
dup_post() { # audit, [jeton CSRF : oui|non]
  local token=""
  [ "${2:-oui}" = oui ] && token="&_glpi_csrf_token=$(csrf)"
  code -d "duplicate=1&plugin_grcmanager_audits_id=$1&title=Audit%20copie%20securite&planned_date=2030-01-10&parts[]=scope${token}" \
    "$P/auditduplication.form.php"
}
expect "front/auditduplication.form.php : sans jeton CSRF" 403 "$(dup_post "$(id audit)" non)"
if [ "$ROLE" != admin ]; then
  expect "front/auditduplication.form.php : audit de B" 403 "$(dup_post "$(id audit_B)")"
fi
case $ROLE in
  none|reader)
    expect "front/auditduplication.form.php : sans droit de creer un audit" 403 "$(dup_post "$(id audit)")"
    ;;
esac
if [ "$ROLE" = reader ]; then
  contains "onglet Cycle suivant : refus en lecture seule" "grc-audit-duplication-denied" oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CAudit 'GlpiPlugin%5CGrcmanager%5CAuditDuplication$1' "$(id audit)")"
fi
if [ "$ROLE" = admin ]; then
  contains "onglet Cycle suivant : formulaire" "grc-audit-duplication-form" oui \
    "$(tab GlpiPlugin%5CGrcmanager%5CAudit 'GlpiPlugin%5CGrcmanager%5CAuditDuplication$1' "$(id audit)")"
fi
