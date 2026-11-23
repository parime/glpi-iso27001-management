### Changed

- **Contrôle de navigation bloquant en CI** (issue #1114). La refonte de la navigation (#1035)
  étant terminée, le contrôle d'ergonomie (réglages, menus, en-têtes) n'est plus un simple
  avertissement : une régression arrête la CI. Le parcours de toutes les pages reste en
  avertissement tant que ses anomalies ne sont pas corrigées.

### Fixed

- **Avertissements lisibles** (issue #1114). Les messages d'avertissement écrits en jaune (contrôles
  d'une violation de données, recette d'une mise en production, matrice des responsabilités,
  acceptation d'un risque, appétence, import de risques, atelier 5 EBIOS, développement sécurisé,
  applications approuvées, résumé de contexte) utilisent la variante foncée du jaune : le contraste
  avec le fond atteint le niveau AA.
