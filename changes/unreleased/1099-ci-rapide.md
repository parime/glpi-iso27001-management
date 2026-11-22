### Changed

- **CI plus rapide** (#1099) : une pull request qui ne touche que la documentation (`docs/`,
  `changes/`, fichiers `.md`, images) ne démarre plus aucun GLPI (environ 8 min au lieu de 30 à
  40) ; un nouveau commit sur une PR annule l'exécution précédente. Les push sur `dev` et `main`
  et les publications lancent toujours tous les contrôles. Contrôles de sécurité communs v1.11.

### Fixed

- L'onglet « lien sécurisé » d'une évaluation de fournisseur n'apparaît plus quand le portail
  fournisseur est désactivé (#1099).
