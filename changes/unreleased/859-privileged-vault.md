### Added

- **Comptes à privilèges et coffre-fort de mots de passe** (issue #859, mesures A.5.17 et A.8.2).
  Nouvelle page **GRC > Accès et comptes > Comptes privilégiés** : éléments du coffre (comptes
  partagés, mots de passe des équipements) et membres, importés en CSV de métadonnées (Bitwarden,
  Vaultwarden par l'interface `bw`, solution PAM) ou, pour les membres, lus sur clic par l'API
  publique d'organisation de Bitwarden. Un fichier qui peut contenir un secret (mot de passe, code
  TOTP, notes, champs personnalisés) est refusé en entier. Écarts signalés : mot de passe non changé
  dans le délai choisi (onglet Personnes et politiques), personne partie qui a encore accès, membre
  inconnu de GLPI, administrateur du coffre sans connexion en deux étapes, compte d'administration
  d'un contrôleur de serveur absent du coffre. Repris dans un onglet des campagnes de revue des
  accès, en preuve des mesures A.5.17 et A.8.2 et dans la source de KRI « mots de passe partagés non
  changés ». Connexion à Bitwarden dans l'onglet Connecteurs ; lecture seule, rien n'est modifié.
