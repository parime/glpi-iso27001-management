# shellcheck shell=bash
# Issue #1045 : reglages du programme d'audit, de la preparation a la certification, du dossier de
# preuves (onglet controls) et de l'entite DORA (onglet frameworks, DORA active par setup.php) dans
# la configuration du plugin. Fragment charge par tests/security/access-matrix.sh.
# Sans droit : envoi refuse. En lecture : blocs affiches sans bouton, envoi refuse. Avec le droit :
# blocs modifiables. Les pages d'exploitation n'ont plus de formulaire de reglages, mais un lien.
SETTINGS_POSTS="update_audit_programme=1&cycle_start=2025 update_readiness_settings=1&coverage_target=90 update_evidence_pack=1&max_mb=50 update_dora_entity=1&name=securite"
case $ROLE in
  none|reader)
    for d in $SETTINGS_POSTS; do
      expect "front/config.php : ${d%%=*} sans droit de modifier" 403 \
        "$(code -d "$d&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    done ;;
esac
case $ROLE in
  reader|limited|admin)
    page=$(body "$P/config.php?tab=controls")
    for id in grc-audit-programme-settings grc-readiness-settings grc-evidence-pack-settings; do
      contains "configuration, onglet controls : bloc $id" "id=\"$id\"" oui "$page"
    done
    contains "configuration, onglet frameworks : entite DORA" 'id="grc-dora-settings"' oui \
      "$(body "$P/config.php?tab=frameworks")"
    EDIT=oui; [ "$ROLE" = reader ] && EDIT=non
    contains "configuration : reglages du programme modifiables" 'name="update_audit_programme"' $EDIT "$page"
    contains "configuration : reglages de la preparation modifiables" 'name="update_readiness_settings"' $EDIT "$page"
    contains "configuration : limite du dossier de preuves modifiable" 'name="update_evidence_pack"' $EDIT "$page"
    for p in auditprogramme.php certificationreadiness.php evidencepack.php doraregister.php; do
      page=$(body "$P/$p")
      contains "front/$p : lien vers les reglages" 'front/config.php?tab=' oui "$page"
      contains "front/$p : plus de formulaire de reglages" 'name="save_settings"' non "$page"
      contains "front/$p : plus de formulaire de reglages (preparation)" 'name="save_readiness_settings"' non "$page"
    done ;;
esac
