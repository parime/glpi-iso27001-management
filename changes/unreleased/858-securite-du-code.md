### Added

- Sécurité du code lue dans GitHub et GitLab, en lecture seule (#858) : nouvelle page **Sécurité du
  code** de la rubrique Mesures, à côté du développement sécurisé, et onglet du même nom sur les
  applications. Pour chaque application du registre dont la fiche donne un dépôt GitHub (github.com
  ou GitHub Enterprise Server) ou GitLab : alertes ouvertes des dépendances vulnérables (Dependabot,
  analyse des dépendances et des conteneurs), des secrets exposés (secret scanning, détection de
  secrets) et de l'analyse du code (code scanning, SAST, DAST), et analyses non activées. Lecture sur
  clic, ou chaque jour par la tâche automatique `codesecurity` (désactivée à l'installation), avec un
  jeton en lecture seule chiffré par GLPI et jamais réaffiché ; GitHub n'est appelé qu'en GET, GitLab
  que par une requête GraphQL de lecture constante, et la valeur d'un secret n'est jamais demandée.
  Alertes classées par gravité et ancienneté, échéance de la politique de correction des
  vulnérabilités (un secret exposé prend le délai le plus court) ; un secret que rien ne dit révoqué
  est signalé en priorité. Deux KRI (secrets exposés non révoqués, alertes hors délai) et preuves
  A.8.28 et A.8.29. Rien n'est modifié dans GitHub ni GitLab.
