### Added

- **Chemins d'attaque vers Domain Admins** (issue #835, lot 3, mesures A.5.15, A.5.18 et A.8.2). La
  page Audit de sécurité de l'Active Directory importe, en lecture seule, l'export JSON du graphe de
  BloodHound CE ou d'Adalanche (format Cytoscape) : nombre de comptes ayant un chemin vers le groupe
  Domain Admins (utilisateurs, ordinateurs, plus court chemin), comptes les plus exposés (nom, type,
  nombre de sauts et de routes) et évolution d'un export au suivant (écart, nouveaux comptes). Lecture
  sûre : taille, profondeur du JSON, nombre de nœuds et de liens bornés, noms nettoyés, aucune autre
  propriété du graphe gardée. Sur clic, le risque est créé dans le registre (une fois par domaine,
  repris par les exports suivants). Preuve sur les fiches des mesures A.5.15, A.5.18 et A.8.2, source
  de KRI « comptes ayant un chemin d'attaque vers Domain Admins », exemples fictifs téléchargeables.
  Un graphe importé par erreur dans le formulaire des rapports est renvoyé vers la bonne partie.

### Fixed

- **Audit de sécurité de l'Active Directory** : supprimer un import ou proposer un ticket pour une
  règle hors de vos entités affiche de nouveau le refus d'accès au lieu d'une erreur sous GLPI 12.
