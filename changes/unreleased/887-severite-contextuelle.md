### Added

- **Niveau d'une vulnérabilité ajusté au contexte des machines** (#887, A.8.8) : l'onglet
  « Classification C/I/D » d'un actif indique son environnement (production, test ou recette,
  développement) et son exposition à Internet, déduite par défaut des flux actifs de publication
  vers Internet de la matrice des flux. Une option des délais de correction (désactivée par
  défaut) rend une vulnérabilité un cran plus urgente quand une machine touchée est joignable
  depuis Internet, et un cran moins urgente quand toutes sont en test ou en développement ; les
  vulnérabilités exploitées activement (KEV) ne bougent pas et une échéance connue n'est jamais
  repoussée. La page « Vulnérabilités du parc » signale le niveau relevé ou abaissé et propose
  d'écarter un résultat avec une justification tracée dans une dérogation.
