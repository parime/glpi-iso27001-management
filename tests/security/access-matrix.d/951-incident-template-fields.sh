# shellcheck shell=bash
# Issue #951 : formulaires d'ajout de champs (masques, obligatoires, predefinis, lecture seule) du
# gabarit d'incident de securite. Meme droit que les gabarits du coeur (itiltemplate, modifier) ;
# le super-admin revient au gabarit apres l'ajout. Fragment charge par tests/security/access-matrix.sh.
# Pages testees : front/securityincidenttemplatehiddenfield.form.php,
# front/securityincidenttemplatemandatoryfield.form.php, front/securityincidenttemplatepredefinedfield.form.php,
# front/securityincidenttemplatereadonlyfield.form.php.

template_field_add() { # page
  local tpl
  tpl=$(id incident_template)
  if [ "$ROLE" = admin ]; then
    expect "front/$1 : ajouter un champ" 302 \
      "$(code -d "add=1&securityincidenttemplates_id=$tpl&num=83&value=0&_glpi_csrf_token=$(csrf)" "$P/$1")"
  else
    expect "front/$1 : ajouter un champ sans le droit des gabarits" 403 \
      "$(code -d "add=1&securityincidenttemplates_id=$tpl&num=83&value=0&_glpi_csrf_token=$(csrf)" "$P/$1")"
  fi
  expect "front/$1 : ajouter sans jeton CSRF" 403 \
    "$(code -d "add=1&securityincidenttemplates_id=$tpl&num=83&value=0" "$P/$1")"
}

template_field_add securityincidenttemplatehiddenfield.form.php
template_field_add securityincidenttemplatemandatoryfield.form.php
template_field_add securityincidenttemplatepredefinedfield.form.php
template_field_add securityincidenttemplatereadonlyfield.form.php
