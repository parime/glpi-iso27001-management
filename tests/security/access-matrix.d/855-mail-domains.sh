# shellcheck shell=bash
# Issue #855 : securite de la messagerie et des domaines, domaines de l'entite A seulement ; saisie,
# modification, suppression et lecture DNS refusees en lecture seule ; ajout du gestionnaire avec jeton CSRF.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
readable front/maildomainsecurity.php
if [ "$ROLE" = reader ]; then
  contains "messagerie : domaine de A" "securite-a.example" oui "$(body "$P/maildomainsecurity.php")"
  contains "messagerie : domaine de B absent" "securite-b.example" non "$(body "$P/maildomainsecurity.php")"
  expect "front/maildomainsecurity.php : ajouter en lecture seule" 403     "$(code -d "add_domain=1&name=securite-c.example&_glpi_csrf_token=$(csrf)" "$P/maildomainsecurity.php")"
  expect "front/maildomainsecurity.php : modifier en lecture seule" 403     "$(code -d "update_domain=1&id=1&name=securite-c.example&_glpi_csrf_token=$(csrf)" "$P/maildomainsecurity.php")"
  expect "front/maildomainsecurity.php : supprimer en lecture seule" 403     "$(code -d "delete_domain=1&id=1&_glpi_csrf_token=$(csrf)" "$P/maildomainsecurity.php")"
  expect "front/maildomainsecurity.php : lire le DNS en lecture seule" 403     "$(code -d "check_all_domains=1&_glpi_csrf_token=$(csrf)" "$P/maildomainsecurity.php")"
  expect "front/maildomainsecurity.php : regler le serveur DNS en lecture seule" 403     "$(code -d "save_dns_server=1&dns_server=192.0.2.53&_glpi_csrf_token=$(csrf)" "$P/maildomainsecurity.php")"
fi
if [ "$ROLE" = limited ]; then
  expect "front/maildomainsecurity.php : ajouter un domaine" 302     "$(code -d "add_domain=1&name=securite-ajout.example&_glpi_csrf_token=$(csrf)" "$P/maildomainsecurity.php")"
  expect "front/maildomainsecurity.php : ajouter sans jeton CSRF" 403     "$(code -d "add_domain=1&name=securite-ajout.example" "$P/maildomainsecurity.php")"
fi
