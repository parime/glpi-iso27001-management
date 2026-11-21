# shellcheck shell=bash
# Issue #835 : audit de securite de l'AD, rapport de l'entite A seulement ; tout en lecture seule.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/adsecurityaudit.php
if [ "$ROLE" = reader ]; then
  contains "audit de l'AD : rapport de A" "adsecurite-a.test" oui "$(body "$P/adsecurityaudit.php")"
  contains "audit de l'AD : rien de B" "adsecurite-b.test" non "$(body "$P/adsecurityaudit.php")"
  contains "audit de l'AD : rapport de B demande par son numero" "Constat AD securite B" non \
    "$(body "$P/adsecurityaudit.php?id=$(id adaudit_B)")"
  expect "front/adsecurityaudit.php : supprimer un import en lecture seule" 403 \
    "$(code -d "clear_ad_audit=1&audit_id=$(id adaudit_A)&_glpi_csrf_token=$(csrf)" "$P/adsecurityaudit.php")"
  expect "front/adsecurityaudit.php : ticket en lecture seule" 403 \
    "$(code -d "ad_audit_ticket=1&rule_id=$(id adrule_A)&_glpi_csrf_token=$(csrf)" "$P/adsecurityaudit.php")"
  expect "front/adsecurityaudit.php : import en lecture seule" 403 \
    "$(code -d "import_ad_audit=1&_glpi_csrf_token=$(csrf)" "$P/adsecurityaudit.php")"
fi
if [ "$ROLE" = limited ]; then
  # Issue #835 : import et ticket de l'entite B refuses ; action proposee pour une regle de B vide.
  expect "front/adsecurityaudit.php : supprimer un import de B" 403 \
    "$(code -d "clear_ad_audit=1&audit_id=$(id adaudit_B)&_glpi_csrf_token=$(csrf)" "$P/adsecurityaudit.php")"
  expect "front/adsecurityaudit.php : ticket pour une regle de B" 403 \
    "$(code -d "ad_audit_ticket=1&rule_id=$(id adrule_B)&_glpi_csrf_token=$(csrf)" "$P/adsecurityaudit.php")"
  expect "front/adsecurityaudit.php : supprimer un import sans jeton CSRF" 403 \
    "$(code -d "clear_ad_audit=1&audit_id=$(id adaudit_A)" "$P/adsecurityaudit.php")"
  contains "action proposee depuis une regle de B : rien de B" "Constat AD securite B" non \
    "$(tab 'GlpiPlugin%5CGrcmanager%5CNonconformity' 'GlpiPlugin%5CGrcmanager%5CNonconformity$main' "0&adauditrule=$(id adrule_B)")"
  contains "action proposee depuis une regle de A : pre-remplie" "Constat AD securite A" oui \
    "$(tab 'GlpiPlugin%5CGrcmanager%5CNonconformity' 'GlpiPlugin%5CGrcmanager%5CNonconformity$main' "0&adauditrule=$(id adrule_A)")"
fi
