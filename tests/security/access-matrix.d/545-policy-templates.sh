# shellcheck shell=bash
# Issue #545 : catalogue de modeles de politiques ; page en lecture, import reserve au droit de
# creer des politiques. Fragment charge par tests/security/access-matrix.sh (memes fonctions).
readable front/policytemplate.php
if [ "$ROLE" = reader ]; then
  expect "front/policytemplate.php : import en lecture seule" 403 \
    "$(code -d "import=1&keys[]=backup&language=en&_glpi_csrf_token=$(csrf)" "$P/policytemplate.php")"
fi
