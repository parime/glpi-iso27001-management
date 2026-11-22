### Added

- Contrôleurs de gestion des serveurs, lot 3 (#843) : santé détaillée du serveur lue par Redfish en
  lecture seule (alimentations, disques, ventilateurs, températures, panne de disque annoncée) et
  événements récents du journal du contrôleur (LogServices), affichés dans l'onglet « Contrôleur de
  gestion » de l'ordinateur ; source « Santé du serveur » dans la vue Sécurité 360° ; lecture
  planifiée de tous les contrôleurs Redfish par la tâche automatique `bmcread`, désactivée à
  l'installation. Uniquement des requêtes GET vers l'adresse configurée, aucune action sur le serveur.
