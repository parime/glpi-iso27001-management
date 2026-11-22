# shellcheck shell=bash
# Issue #1044 : regles et seuils du parc dans l'onglet Parc de la configuration du plugin (chiffrement,
# delais des correctifs, licences surveillees, journalisation attendue). Fragment charge par
# tests/security/access-matrix.sh (memes fonctions et variables).
# Les pages d'exploitation n'ont plus de formulaire de reglage, seulement un lien vers l'onglet ; les
# anciens envois restent acceptes (controles d'origine) et renvoient sur l'onglet.
case $ROLE in
  reader)
    page=$(body "$P/config.php?tab=assets")
    for anchor in grc-encryption-rule grc-patch-targets grc-log-settings grc-license-watch; do
      contains "configuration, onglet Parc : bloc $anchor en lecture" "id=\"$anchor\"" oui "$page"
    done
    contains "configuration, onglet Parc : regle de chiffrement non modifiable" 'name="save_encryption_rule"' non "$page"
    expect "front/config.php : regle de chiffrement en lecture seule" 403 \
      "$(code -d "save_encryption_rule=1&encryption_scope=all&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : delais des correctifs en lecture seule" 403 \
      "$(code -d "save_patch_targets=1&patch_targets%5Bcritical%5D=15&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : editeurs surveilles en lecture seule" 403 \
      "$(code -d "save_license_watch=1&license_publishers=securite&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : journalisation attendue en lecture seule" 403 \
      "$(code -d "save_log_settings=1&log_scope=all&_glpi_csrf_token=$(csrf)" "$P/config.php")" ;;
  limited|admin)
    page=$(body "$P/config.php?tab=assets")
    for button in save_encryption_rule save_patch_targets save_log_settings; do
      contains "configuration, onglet Parc : $button modifiable" "name=\"$button\"" oui "$page"
    done
    # Le gestionnaire limite n'a pas la lecture native des licences : le bloc des licences, comme leur
    # page, lui reste cache ; le super-admin le voit et le modifie.
    if [ "$ROLE" = admin ]; then SEE_LICENSE=oui; else SEE_LICENSE=non; fi
    contains "configuration, onglet Parc : editeurs surveilles" 'name="save_license_watch"' $SEE_LICENSE "$page"
    # Pages d'exploitation : plus de formulaire de reglage, un lien vers l'onglet Parc.
    for p in diskencryption:save_encryption_rule:grc-encryption-rule patchcompliance:save_patch_targets:grc-patch-targets \
             logcoverage:save_log_settings:grc-log-settings; do
      IFS=: read -r front button anchor <<< "$p"
      html=$(body "$P/$front.php")
      contains "front/$front.php : sans formulaire de reglage" "name=\"$button\"" non "$html"
      contains "front/$front.php : lien vers l'onglet Parc" "config.php?tab=assets#$anchor" oui "$html"
    done
    contains "front/diskencryption.php : declaration des cles toujours sur la page" 'name="declare_key"' oui \
      "$(body "$P/diskencryption.php")"
    # Envoi du bloc depuis l'onglet (valeur par defaut, rien ne change), jamais sans jeton CSRF.
    expect "front/config.php : delais des correctifs" 302 \
      "$(code -d "save_patch_targets=1&patch_targets%5Bcritical%5D=15&_glpi_csrf_token=$(csrf)" "$P/config.php")"
    expect "front/config.php : delais des correctifs sans jeton CSRF" 403 \
      "$(code -d "save_patch_targets=1&patch_targets%5Bcritical%5D=3" "$P/config.php")"
    # Ancien envoi depuis la page d'exploitation : accepte, renvoye sur l'onglet Parc.
    expect "front/patchcompliance.php : ancien envoi renvoye sur l'onglet Parc" \
      "config.php?tab=assets#grc-patch-targets" \
      "$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" -H "Sec-Fetch-Site: same-origin" \
        -d "save_patch_targets=1&patch_targets%5Bcritical%5D=15&_glpi_csrf_token=$(csrf)" "$P/patchcompliance.php" \
        | grep -o 'config.php?tab=assets#grc-patch-targets')" ;;
esac
