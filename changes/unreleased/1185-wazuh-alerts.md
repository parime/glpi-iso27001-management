### Added

- **Alertes Wazuh transformées en incidents de sécurité** (issue #1185, mesures A.5.24 à A.5.26).
  Assistance > Incidents de sécurité, onglet Import des alertes EDR : quand le plugin GLPI Wazuh est
  actif, le cadre « Alertes Wazuh à revoir » liste les alertes des 30 derniers jours sur les
  ordinateurs visibles, regroupées par règle et ordinateur (alertes répétées), avec le niveau de la
  règle, l'agent, les horodatages et un lien vers les alertes dans le plugin Wazuh, filtrables par
  niveau minimal. Les groupes cochés deviennent des incidents de sécurité (origine SIEM, gravité
  tirée du niveau, ordinateur rattaché, techniques MITRE ATT&CK de la règle) ; une alerte n'est
  importée qu'une fois et ses répétitions s'ajoutent à l'incident tant qu'il reste ouvert. Droit de
  créer un incident, lecture seule des tables du plugin Wazuh.
- **Ordinateurs avec des vulnérabilités critiques non traitées, d'après Wazuh** (issue #1185, mesure
  A.8.8). Parc > Sécurité du parc, cadre « Détectées par Wazuh seulement » : ordinateurs touchés par
  chaque vulnérabilité (nommés avec le droit de lecture des ordinateurs) et, en tête, les ordinateurs
  avec une vulnérabilité critique (CVSS de 9 ou plus) toujours signalée par Wazuh et sans dérogation
  validée en cours. Même indicateur en carte du tableau de bord et en source de KRI.
