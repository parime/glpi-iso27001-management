# shellcheck shell=bash
# Issue #951 : formulaires d'ajout de champs (masques, obligatoires, predefinis, lecture seule) du
# gabarit d'incident de securite. Meme droit que les gabarits du coeur (itiltemplate, modifier) ;
# le super-admin revient au gabarit apres l'ajout. Fragment charge par tests/security/access-matrix.sh.

tpl=$(id incident_template)
for kind in hidden mandatory predefined readonly; do
  page="securityincidenttemplate${kind}field.form.php"
  if [ "$ROLE" = admin ]; then
    expect "front/$page : ajouter un champ" 302 \
      "$(code -d "add=1&securityincidenttemplates_id=$tpl&num=83&value=0&_glpi_csrf_token=$(csrf)" "$P/$page")"
  else
    expect "front/$page : ajouter un champ sans le droit des gabarits" 403 \
      "$(code -d "add=1&securityincidenttemplates_id=$tpl&num=83&value=0&_glpi_csrf_token=$(csrf)" "$P/$page")"
  fi
  expect "front/$page : ajouter sans jeton CSRF" 403 \
    "$(code -d "add=1&securityincidenttemplates_id=$tpl&num=83&value=0" "$P/$page")"
done
