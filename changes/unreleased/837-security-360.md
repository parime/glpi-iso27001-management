### Added

- **Vue Sécurité 360° de chaque actif** (issue #837, partie 1). Un onglet **Sécurité 360°** sur les
  ordinateurs et les matériels réseau résume, pour l'actif, chaque source de sécurité disponible avec
  un badge d'état (critique, à surveiller, conforme, information), un court résumé et un lien vers le
  détail : vulnérabilités connues, chiffrement des disques, conformité de configuration (OpenSCAP),
  contrôleur de gestion, incidents de sécurité et risques liés. Un score sur 100 et un niveau global
  (bon, à améliorer, faible) sont expliqués sur la page : moyenne pondérée des sources évaluées, et
  une seule source critique empêche le niveau « bon ». Les sources sans données (module non utilisé,
  rien d'importé, pas le droit) restent masquées. Les sources viennent d'un registre extensible
  (`AssetSecuritySourcesProvider` dans `src/Feature/`) : un module ajoute la sienne sans toucher à
  l'onglet. Lecture seule, calculé seulement à l'ouverture de l'onglet.
