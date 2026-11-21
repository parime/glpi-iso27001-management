# Traductions à publier (fragments)

Une PR qui ajoute ou modifie un texte ne touche plus aux fichiers `locales/*.po`, au `.pot` ni
aux `.mo` (ces derniers ne sont plus versionnés) : elle crée ici **un fichier par langue de base**,
`<issue>-<sujet>.<langue>.po`, qui ne contient que ses entrées. Exemple pour l'issue 123 :

```
locales/unreleased/123-supplier-certificates.de_DE.po
locales/unreleased/123-supplier-certificates.en_GB.po
locales/unreleased/123-supplier-certificates.es_ES.po
locales/unreleased/123-supplier-certificates.fr_FR.po
locales/unreleased/123-supplier-certificates.it_IT.po
locales/unreleased/123-supplier-certificates.pl_PL.po
locales/unreleased/123-supplier-certificates.pt_BR.po
```

Contenu de `123-supplier-certificates.fr_FR.po` :

```po
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\n"

msgid "Supplier certificates"
msgstr "Certificats des fournisseurs"

msgid "%d certificate"
msgid_plural "%d certificates"
msgstr[0] "%d certificat"
msgstr[1] "%d certificats"
```

- Les sept langues de base sont obligatoires, avec les mêmes `msgid` ; `en_GB` reprend le texte
  anglais. Les variantes régionales (`fr_CA`, `es_MX`, `en_US`...) n'ont pas de fragment : elles
  suivent leur langue de base.
- `Plural-Forms` n'est nécessaire que si le fragment contient un pluriel : reprendre la ligne de
  l'en-tête du `.po` complet de la langue.
- Une entrée d'un fragment remplace celle du `.po` complet : c'est aussi le moyen de corriger une
  traduction existante.
- Vérifier : `python3 tools/build_locales.py --check` (GNU gettext requis). La CI contrôle en plus
  que chaque chaîne du code est dans le `.pot` ou dans un fragment, et l'inverse.
- `python3 tools/build_locales.py` compile les `.mo` (`.po` + fragments) pour essayer la branche
  sur une instance GLPI.
- À la publication, `build_locales.py --release` range les fragments dans les `.po` complets
  (`msgcat`), les supprime, régénère le `.pot`, resynchronise les variantes et compile les `.mo`.
