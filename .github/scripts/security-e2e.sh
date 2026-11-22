#!/usr/bin/env bash
# Tests de securite de bout en bout, communs aux jobs GLPI 11 et GLPI 12 (issue #980) : avant, la
# matrice d'acces et le test XSS ne tournaient que sur GLPI 11.
#
# Usage : security-e2e.sh matrix|xss|js|edit|purge [url GLPI]
#   matrix : matrice de controle d'acces (profils limites a une entite, puis super-admin)
#   xss    : charge piegee dans chaque champ texte libre, aucune page ne la restitue sans echappement
#   js     : erreurs JavaScript et boites de dialogue dans Chromium (tests/security/js-errors.py), dans
#            l'image Playwright officielle : Playwright n'installe pas Chromium sur le runner Ubuntu 26.04
#   edit   : scenarios d'edition dans Chromium (tests/security/edit-scenarios.py), sur le jeu de donnees
#   purge  : purge du jeu de donnees (tests/security/purge.php), puis nouvelle creation
# Variable : GLPI_CONTAINER (conteneur GLPI, par defaut « glpi »).
set -euo pipefail
STEP=$1 BASE=${2:-http://localhost:8080} C=${GLPI_CONTAINER:-glpi}
# Image officielle Playwright Python, epinglee par digest : Chromium et ses bibliotheques ; le paquet
# Python playwright (meme version) s'installe au lancement, l'image ne l'inclut pas.
PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright/python:v1.57.0-noble@sha256:3de745b23fc4b33fccbcb3f592ee52dd5c80ce79f19f839c825ce23364e403c1
setup() { docker exec -u www-data "$C" php /var/www/glpi/plugins/grcmanager/tests/security/setup.php "$@" | tail -1; }
# Lance un script Python dans l'image Playwright : browser <script> <arguments...>
# Le navigateur partage le reseau du conteneur GLPI et l'ouvre a l'adresse http://localhost, celle que
# GLPI connait (url_base) : avec le port publie 8080, les redirections de GLPI vers http://localhost
# (port 80) echouaient (ERR_CONNECTION_REFUSED).
BROWSER_BASE=${BROWSER_BASE:-http://localhost}
browser() {
  docker run --rm --network "container:$C" --ipc=host -v "$PWD:/w:ro" -w /w "$PLAYWRIGHT_IMAGE" \
    sh -c 'pip install --quiet --disable-pip-version-check playwright==1.57.0 && exec python "$@"' sh "$@"
}
matrix() { bash tests/security/access-matrix.sh "$BASE" "$@"; }

case $STEP in
  matrix)
    # bin/console tourne en root dans les jobs : rend files/ a Apache (www-data).
    docker exec -u root "$C" chown -R www-data:www-data /var/glpi/files
    ids=$(setup); echo "$ids"
    # Issue #1002 : le jeu de donnees se relance sans erreur et sans doublon sur une base deja
    # utilisee : memes identifiants (hors liens du portail fournisseur, recrees a chaque passage) et
    # meme nombre de lignes dans chaque table du plugin.
    counts_first=$(setup counts)
    ids_again=$(setup) || { echo "::error::setup.php echoue a la deuxieme execution sur la meme base"; exit 1; }
    same='with_entries(select(.key | startswith("portal_token_") | not))'
    [ "$(jq -S "$same" <<< "$ids")" = "$(jq -S "$same" <<< "$ids_again")" ] \
      || { echo "::error::setup.php relance ne retrouve pas les memes objets"; diff <(jq -S "$same" <<< "$ids") <(jq -S "$same" <<< "$ids_again") || true; exit 1; }
    counts_again=$(setup counts)
    [ "$counts_first" = "$counts_again" ] \
      || { echo "::error::setup.php relance a cree des doublons"; diff <(jq -S . <<< "$counts_first") <(jq -S . <<< "$counts_again") || true; exit 1; }
    echo "OK  jeu de donnees relance sans erreur ni doublon"
    # La deuxieme execution recree les liens du portail fournisseur : ce sont eux qui sont valides.
    ids=$ids_again
    matrix limited iso-gestionnaire 'Secrev#2026' "$ids"
    links=$(setup risk-links "$(jq -r .risk <<< "$ids")")
    jq -e --arg b "Computer:$(jq -r .computer_B <<< "$ids")" 'index($b) != null' <<< "$links" > /dev/null \
      || { echo "::error::enregistrer le risque a efface le lien vers un actif invisible pour l'utilisateur : $links"; exit 1; }
    approvers=$(setup soa-approvers)
    jq -e --argjson me "$(jq -r .manager <<< "$ids")" '.approvers | index($me) == null' <<< "$approvers" > /dev/null \
      || { echo "::error::le demandeur s'est designe approbateur de sa propre SoA : $approvers"; exit 1; }
    echo "OK  liens du risque conserves ($links), demandeur absent des approbateurs ($approvers)"
    # Issue #375 : enregistrer le traitement de A ne retire pas l'ordinateur de B, invisible pour lui.
    plinks=$(setup processing-links "$(jq -r .processing_A <<< "$ids")")
    jq -e --arg b "Computer:$(jq -r .computer_B <<< "$ids")" --arg a "Computer:$(jq -r .computer_A <<< "$ids")" \
      'index($b) != null and index($a) != null' <<< "$plinks" > /dev/null \
      || { echo "::error::enregistrer le traitement a modifie un lien vers un actif invisible pour l'utilisateur : $plinks"; exit 1; }
    echo "OK  liens du traitement conserves ($plinks)"
    counts_before=$(setup counts)
    matrix none iso-sans-droit 'Secrev#2026' "$ids"
    matrix reader iso-lecteur 'Secrev#2026' "$ids"
    # Registres sensibles : droit general du SMSI sans le droit dedie.
    matrix general iso-general 'Secrev#2026' "$ids"
    counts_after=$(setup counts)
    [ "$counts_before" = "$counts_after" ] || { echo "::error::un profil sans droit ou en lecture seule a cree ou supprime des donnees"; echo "avant : $counts_before"; echo "apres : $counts_after"; exit 1; }
    echo "OK  aucune ligne creee ni supprimee par les profils sans droit et lecteur"
    matrix admin glpi glpi "$ids"
    ;;
  xss)
    ids=$(setup)
    xss=$(setup xss "$ids")
    bash tests/security/xss-check.sh "$BASE" "$ids" "$xss"
    ;;
  js) browser tests/security/js-errors.py "$BROWSER_BASE" front ;;
  edit) browser tests/security/edit-scenarios.py "$BROWSER_BASE" "$(setup)" ;;
  purge)
    # Issue #1002 : la purge retire le jeu de donnees (entites, comptes, profils), puis setup.php le
    # recree sans erreur.
    purge() { docker exec -u www-data "$C" php /var/www/glpi/plugins/grcmanager/tests/security/purge.php "$@" | tail -1; }
    purge
    left=$(purge --dry-run)
    jq -e '(.glpi_entities // 0) == 0 and (.glpi_users // 0) == 0 and (.glpi_profiles // 0) == 0' <<< "$left" > /dev/null       || { echo "::error::la purge laisse des donnees de test : $left"; exit 1; }
    setup > /dev/null || { echo "::error::setup.php echoue apres la purge"; exit 1; }
    echo "OK  jeu de donnees purge puis recree"
    ;;
  *) echo "usage : $0 matrix|xss|js|edit|purge [url]" >&2; exit 2 ;;
esac
