### Fixed
- **Test de la cartographie indépendant de la langue** (suite de l'issue #1078). Un test du premier lot de la cartographie comparait le libellé d'interface « Serveur » ; depuis que le contrôle des assertions traduites bloque la CI, il faisait échouer `dev` et toutes les PR. Il utilise maintenant une valeur fictive.
