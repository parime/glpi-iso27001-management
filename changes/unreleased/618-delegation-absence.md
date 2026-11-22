### Added

- **Délégation des décisions du SMSI pendant une absence** (#618, 5.3, A.5.3) : le suppléant
  qu'une personne déclare dans GLPI (préférences, onglet « Suppléants », avec une date de début et
  une date de fin) prend pour son compte, pendant cette période seulement, les décisions qui lui
  sont confiées nommément : lignes d'une revue des accès, approbation d'une version de la SoA ou
  d'une politique. Les types délégables se choisissent dans la configuration (encadré
  « Délégation pendant une absence ») ; aucun ne l'est par défaut, et les dérogations et
  acceptations de risque, qui tiennent à un droit, ne le sont jamais. Le suppléant n'obtient aucun
  droit : il voit dans « Mon espace GRC », « Mes revues d'accès » et les pages d'approbation les
  seules décisions qui attendent l'absent, jamais son propre compte, et ne vote pas deux fois sur
  une version qu'il approuve lui-même. Une période sans date de fin ne délègue rien ; la
  délégation cesse d'elle-même à la fin de l'absence. Chaque décision s'affiche « X pour le compte
  de Y » (historique, procès-verbal et rapport PDF, notifications) et figure dans le journal des
  décisions déléguées (qui, pour le compte de qui, quand, sur quoi, période d'absence) et dans le
  journal d'audit.
