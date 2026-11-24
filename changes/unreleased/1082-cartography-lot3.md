### Added

- **Cartographie du SI, troisième lot** (issue #1082) :
  - nouvelle couche **VLAN et réseaux IP** entre le réseau physique et les serveurs, lue dans les
    objets natifs de GLPI : VLAN des ports réseau, réseaux IP des adresses, champ « Réseau » des
    matériels et relation « Réseau » des éléments d'une application. Le switch porte le VLAN, le VLAN
    porte les machines : la panne d'un switch remonte par ses VLAN. Un VLAN, un réseau IP ou un
    réseau peut servir de point de départ ;
  - **environnements des applications** (production, recette, préproduction : champ natif des
    applicatifs GLPI) : badge sur chaque application, tableau **Applications par environnement**
    avec leurs serveurs et instances, colonne dans les exports CSV et PDF, et filtre
    **Environnement des applications** qui ne parcourt que les applications de l'environnement choisi ;
  - onglet **Cartographie du SI** sur la fiche des applicatifs (ce dont l'application dépend,
    jusqu'au réseau) et applications proposées comme point de départ.
