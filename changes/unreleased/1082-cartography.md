### Added

- **Cartographie du SI par couches, du switch au processus métier** (issue #1082, mesures A.5.9 et
  A.5.30). Nouvel onglet **Cartographie du SI** de la rubrique **Analyses de risques**, à côté de
  l'analyse d'impact et de l'effet domino. À partir d'un élément (matériel réseau, ordinateur,
  logiciel, application, instance de base de données, service Docker, processus métier), la page
  répond à « que touche la panne de cet équipement ? » (jusqu'aux processus métier du BIA, rangés
  par RTO) et à « de quoi dépend ce processus ? » (jusqu'au réseau). Quatre couches : réseau ;
  serveurs, postes et virtualisation ; applications ; processus métier. Rien n'est dessiné à la
  main : la carte lit les connexions réseau, les machines virtuelles (même UUID), les logiciels
  installés, les applications et instances de bases de données de GLPI, l'analyse d'impact de GLPI,
  les services de l'Inventaire Docker s'il est actif, et les dépendances des processus métier.
  Schéma SVG dessiné par le plugin (aucune bibliothèque externe), chaque case joignable au clavier,
  avec la liste par couche en équivalent textuel. Un élément que la personne ne peut pas voir est
  compté sans être nommé. Point d'extension pour d'autres sources de liens
  (`CartographySourcesProvider`).
