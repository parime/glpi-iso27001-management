### Changed

- **Traductions compilées à la construction** (issue #985) : les catalogues `locales/*.mo` ne sont
  plus versionnés. `tools/build_locales.py` les compile depuis les `.po` pour la CI, l'archive
  publiée (contrôlée : chaque langue livrée y a son `.mo`) et le déploiement. Rien ne change pour
  l'utilisateur ; les fusions de PR ne se bloquent plus sur des fichiers binaires.
