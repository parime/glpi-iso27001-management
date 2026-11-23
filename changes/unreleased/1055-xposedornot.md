### Added

- **Surveillance des fuites de données avec XposedOrNot, gratuit** (issue #1055, mesure A.5.7). La
  surveillance n'exige plus de clé payante Have I Been Pwned : dans Configuration > Plugins >
  GRC Manager > **Connecteurs**, on choisit le fournisseur. XposedOrNot vérifie les adresses
  professionnelles sans clé, 20 au plus par passage (le service en accepte 25 par heure et 100 par
  jour), le passage suivant reprenant au même utilisateur ; une clé de domaine XposedOrNot gratuite
  (domaine vérifié sur leur tableau de bord) vérifie tout le domaine en une requête. Mêmes règles
  qu'avant : adresses des domaines déclarés seulement, registre sensible, alerte au groupe RSSI, clé
  chiffrée par GLPI. Le bouton « Tester la connexion » n'envoie aucune adresse réelle. Les résultats
  restent dans GRC > Personnel et sensibilisation > Expositions à des fuites. Une nouvelle
  installation sans clé Have I Been Pwned utilise XposedOrNot par défaut ; une installation qui a déjà
  une clé garde Have I Been Pwned.
