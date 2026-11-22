# shellcheck shell=bash
# Issue #1047 : matrice d'acces de la navigation, pour chacun des cinq profils (sans droit, lecteur,
# sans registres sensibles, gestionnaire limite, super-admin). Chaque page des rubriques de menu
# (liste : setup.d/1037-menu-sections.php, cle menu_pages) doit etre montree si et seulement si le
# profil peut l'ouvrir (200) :
# - une entree de rubrique apparait dans le menu de GLPI (barre laterale) ;
# - une sous-page apparait sur la page de sa rubrique (carte de la page d'accueil ou onglet) ; si la
#   rubrique est interdite au profil, sur la sous-page que le menu montre a sa place.
# Une entree montree qui refuse (menu trompeur) ou une page ouverte que le menu cache (ecran joignable
# seulement par un lien) echouent. GLPI garde le menu en session : nouvelle connexion, dans un pot de
# cookies a part, pour lire le menu des reglages en vigueur apres les cas qui precedent.
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
NAV_JAR=$(mktemp)
nav_get() { curl -s -c "$NAV_JAR" -b "$NAV_JAR" "$@"; }
nav_code() { curl -s -o /dev/null -w '%{http_code}' -c "$NAV_JAR" -b "$NAV_JAR" "$@"; }
nav_token=$(nav_get "$BASE/" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
nav_get -o /dev/null --data-urlencode "login_name=$LOGIN" --data-urlencode "login_password=$PASSWORD" \
  --data-urlencode "_glpi_csrf_token=$nav_token" -d noAUTO=1 "$BASE/front/login.php"
nav_home=$(nav_get "$BASE/front/central.php" | tr '\n\r' '  ')
expect "navigation : nouvelle connexion" oui "$(grep -q '<aside' <<< "$nav_home" && echo oui || echo non)"
# Liens de la barre laterale (entrees de chaque secteur, y compris Assistance, Parc et Gestion).
nav_menu=$(grep -o '<aside.*</aside>' <<< "$nav_home" | grep -o 'href="[^"]*"')
declare -A NAV_PARENT_HTML=()
nav_links_of() { # adresse d'une page de rubrique : ses liens, lus une fois par profil
  if [ -z "${NAV_PARENT_HTML[$1]+x}" ]; then
    NAV_PARENT_HTML[$1]=$(nav_get "$BASE$1" | tr '\n\r' '  ' | grep -o 'href="[^"]*"')
  fi
  printf '%s' "${NAV_PARENT_HTML[$1]}"
}
nav_pages=0
while IFS=$'\t' read -r nav_key nav_url nav_parent nav_sub; do
  nav_pages=$((nav_pages + 1))
  nav_status=$(nav_code "$BASE$nav_url")
  nav_opens=non; [ "$nav_status" = 200 ] && nav_opens=oui
  nav_href="${nav_url//&/&amp;}\""
  if [ "$nav_sub" = false ] && [ "${nav_key%#page}" = "$nav_key" ]; then
    nav_shown=non; grep -qF "$nav_href" <<< "$nav_menu" && nav_shown=oui
    expect "menu : entree $nav_url montree si et seulement si elle s'ouvre ($nav_status)" "$nav_opens" "$nav_shown"
  else
    # Page d'entree de la rubrique pour ce profil : la rubrique elle-meme, ou, si elle lui est
    # interdite, la premiere sous-page que le menu montre a sa place (Menu::withSubPages()).
    nav_entry=$nav_parent
    if [ "$(nav_code "$BASE$nav_parent")" != 200 ]; then
      nav_entry=
      while IFS= read -r nav_sibling; do
        if grep -qF "${nav_sibling//&/&amp;}\"" <<< "$nav_menu"; then nav_entry=$nav_sibling; break; fi
      done < <(jq -r --arg p "$nav_parent" '.menu_pages[] | select(.parent == $p and .sub) | .url' <<< "$IDS")
    fi
    nav_shown=non
    if [ "$nav_entry" = "$nav_url" ]; then
      nav_shown=oui
    elif [ -n "$nav_entry" ] && grep -qF "$nav_href" <<< "$(nav_links_of "$nav_entry")"; then
      nav_shown=oui
    fi
    expect "rubrique $nav_parent : carte ou onglet $nav_url montre si et seulement si il s'ouvre ($nav_status)" \
      "$nav_opens" "$nav_shown"
  fi
done < <(jq -r '.menu_pages[] | [.key, .url, .parent, (.sub|tostring)] | @tsv' <<< "$IDS")
expect "navigation : pages des rubriques verifiees" oui "$([ "$nav_pages" -gt 0 ] && echo oui || echo non)"

# Issue #1047 : les types delegables s'enregistrent par la configuration, plus par la page du journal.
expect "front/config.php : reglage de la delegation sans jeton CSRF" 403 \
  "$(code -d "update_delegation_config=1&delegation_types[]=soa_approval" "$P/config.php")"
case $ROLE in
  none|reader|limited|general)
    expect "front/config.php : reglage de la delegation sans le droit de configuration" 403 \
      "$(code -d "update_delegation_config=1&delegation_types[]=soa_approval&_glpi_csrf_token=$(csrf)" "$P/config.php")" ;;
esac
contains "front/config.php : la delegation s'enregistre dans la configuration" 'plugins/grcmanager/front/config.php?tab=people"' \
  "$([ "$ROLE" = none ] && echo non || echo oui)" \
  "$(curl -s -c "$JAR" -b "$JAR" "$P/config.php?tab=people" | grep -o '<form method="post" action="[^"]*"')"
rm -f "$NAV_JAR"
