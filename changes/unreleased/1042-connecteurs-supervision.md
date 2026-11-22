### Changed

- **Connecteurs de supervision réglés dans la configuration** (#1042) : la connexion à Zabbix,
  à Uptime Kuma et à Veeam Backup & Replication, ainsi que le serveur DNS des contrôles de
  messagerie, se règlent désormais dans Configuration > Plugins > GRC Manager, onglet
  **Connecteurs** : un bloc par connecteur avec l'adresse, le secret (chiffré par GLPI, jamais
  réaffiché), **Tester la connexion** et la date de la dernière lecture. Les actifs à superviser
  (Zabbix) et les seuils (Uptime Kuma) suivent leur connecteur ; les actifs à sauvegarder et les
  fréquences attendues passent dans l'onglet **Parc**. Les pages d'exploitation gardent les
  résultats, le bouton « Lire maintenant » et un bouton **Régler la connexion**. Un ancien envoi
  de réglage depuis ces pages reste accepté pendant une version et mène au bon onglet. Les droits
  ne changent pas.
