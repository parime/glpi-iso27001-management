### Added

- Services exposés lus dans Uptime Kuma, en lecture seule (#842) : nouvelle page **Services exposés
  (Uptime Kuma)** de la rubrique Sécurité technique. Lecture sur clic, ou toutes les heures par la
  tâche automatique `uptimekuma` (désactivée à l'installation), de l'endpoint Prometheus `/metrics`
  (clé d'API d'Uptime Kuma, chiffrée par GLPI et jamais réaffichée) et des pages de statut publiques
  choisies ; uniquement des requêtes GET, rien n'est modifié dans Uptime Kuma. Les sondes sont
  rapprochées des ordinateurs, matériels réseau et applications par le nom d'hôte surveillé ; les
  sondes sans actif et les actifs publiés sur Internet (matrice des flux) sans sonde sont signalés.
  Disponibilité et plus longue panne de la période calculées sur les relevés successifs, comparées au
  RTO et à la criticité des processus métier qui dépendent de l'actif (ou choisis pour la sonde).
  Certificats TLS : jours restants, seuil d'alerte réglable, aucun renouvellement automatique. Une
  panne longue peut être proposée comme incident de sécurité, sur clic, puis annoncée aux utilisateurs
  par la communication de crise. Trois KRI (disponibilité des services exposés, certificats à
  renouveler, actifs exposés sans sonde), preuves A.8.16, A.5.30 et A.8.24, source « Services exposés
  (Uptime Kuma) » de la vue Sécurité 360°.
