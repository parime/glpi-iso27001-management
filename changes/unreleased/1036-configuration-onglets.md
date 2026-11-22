### Changed

- **Page de configuration du plugin en onglets** (#1036) : les réglages, jusqu'ici sur une seule
  longue page, sont rangés en huit onglets (Général, Référentiels, Risques, Mesures, audits et
  preuves, Personnes et politiques, Incidents et continuité, Connecteurs, Parc). Aucun réglage
  ne change. L'onglet ouvert est dans l'adresse (`front/config.php?tab=risks`...) et seul cet
  onglet est chargé : la page s'ouvre bien plus vite. Les anciens liens vers un bloc
  (`config.php#grcmanager-recyf`...) ouvrent le bon onglet. La page est rangée sous
  Configuration > Plugins (et plus sous « Outils »). Un onglet n'apparaît que si l'on a le droit
  d'au moins un de ses réglages ; en lecture seule, les boutons d'enregistrement ne sont plus
  affichés (groupe RSSI des audits, module des incidents, enrichissement NVD, matrice des
  risques).

### Fixed

- Bouton « Enregistrer » du réglage de fin de vie (endoflife.date) traduit dans toutes les
  langues (#1036).
