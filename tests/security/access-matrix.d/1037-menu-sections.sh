# shellcheck shell=bash
# Issue #1037 : chaque page des rubriques de menu declarees dans src/Navigation/Section/ est bien
# servie. Super-admin : 200, fil d'Ariane « Secteur > Rubrique > Sous-page » et rubrique surlignee
# dans le menu. Sans droit du plugin : refus propre (403 avec une page, jamais une page blanche ni une
# erreur 500), sauf si le menu lui montre la page (elle doit alors s'ouvrir).
# Liste des pages : setup.d/1037-menu-sections.php (cle menu_pages du jeu de donnees).
# Fragment charge par tests/security/access-matrix.sh (memes fonctions et variables).
menu_page_check() { # adresse, adresse de la rubrique, sous-page (true|false), visible sans droit (true|false)
  local url=$1 parent=$2 sub=$3 none=$4 out status html crumbs active
  out=$(curl -s -w '\n%{http_code}' -c "$JAR" -b "$JAR" "$BASE$url")
  status=${out##*$'\n'}
  html=$(tr '\n\r' '  ' <<< "${out%$'\n'*}")
  case $ROLE in
    admin)
      expect "$url : super-admin" 200 "$status"
      crumbs=$(grep -o '<ol class="breadcrumb[^"]*">.*' <<< "$html" | sed 's#</ol>.*##')
      contains "$url : fil d'Ariane, rubrique" "${parent//&/&amp;}\"" oui "$crumbs"
      if [ "$sub" = true ]; then
        contains "$url : fil d'Ariane, sous-page" "${url//&/&amp;}\"" oui "$crumbs"
      fi
      active=$(grep -o '<aside.*</aside>' <<< "$html" | grep -o '<a [^>]*>' | grep 'class="[^"]*\bactive\b')
      contains "$url : rubrique surlignee dans le menu" "${parent//&/&amp;}\"" oui "$active"
      ;;
    none)
      if [ "$none" = true ]; then
        expect "$url : montree sans droit, donc ouverte" 200 "$status"
      else
        expect "$url : sans droit" 403 "$status"
        local blank=non
        [ -z "${html// /}" ] && blank=oui
        expect "$url : refus avec une page (pas une page blanche)" non "$blank"
      fi
      ;;
  esac
}
if [ "$ROLE" = admin ] || [ "$ROLE" = none ]; then
  menu_pages_count=$(jq '.menu_pages | length' <<< "$IDS")
  expect "rubriques de menu : pages declarees trouvees" oui "$([ "${menu_pages_count:-0}" -gt 0 ] && echo oui || echo non)"
  while IFS=$'\t' read -r mp_url mp_parent mp_sub mp_none; do
    menu_page_check "$mp_url" "$mp_parent" "$mp_sub" "$mp_none"
  done < <(jq -r '.menu_pages[] | [.url, .parent, (.sub|tostring), (.none|tostring)] | @tsv' <<< "$IDS")
fi
