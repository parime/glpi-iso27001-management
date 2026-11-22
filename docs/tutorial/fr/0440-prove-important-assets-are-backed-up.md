### Prouver que les actifs importants sont sauvegardés (mesures A.8.13 et A.5.30)

Le plugin compare les machines connues de vos outils de sauvegarde aux ordinateurs et matériels
réseau de GLPI. Il lit seulement : il ne lance aucune sauvegarde ni restauration et ne modifie rien
dans l'outil ni dans GLPI. Il faut le droit du plugin et le droit natif de lecture des ordinateurs.

1. Ouvrez Sécurité technique > **Couverture des sauvegardes**. L'encadré « À quoi sert cette page ? »
   rappelle ce qui est attendu.
2. Avec le bouton **Modifier les réglages** (Configuration > Plugins > GRC Manager, onglet **Parc**),
   cadre **Quels actifs doivent être sauvegardés, et à quelle fréquence ?**, choisissez les actifs
   concernés (par défaut les actifs critiques et les serveurs), l'ancienneté maximale par défaut de
   la dernière sauvegarde réussie (48 heures) et la fréquence attendue des tests de restauration
   (12 mois). L'ancienneté admise d'un actif est le plus petit RPO entre celui de l'actif (onglet de
   classification C/I/D) et ceux des processus métier qui l'utilisent ; sans RPO, la valeur par
   défaut s'applique.
3. Pour **Veeam Backup & Replication**, créez dans la console Veeam un compte dédié avec le rôle
   « Veeam Backup Viewer » (lecture seule), puis, avec le bouton **Régler la connexion** (onglet **Connecteurs** de la
   configuration), saisissez l'adresse du serveur (par exemple
   `https://veeam.exemple.test:9419`), le compte, son mot de passe et la révision de l'API REST.
   Le mot de passe est chiffré avec la clé de GLPI et n'est jamais réaffiché ; **Tester la connexion**
   vérifie le compte sans rien lire. Sur la page des sauvegardes, **Lire maintenant depuis Veeam** lit, sur votre clic seulement, les points de restauration des 31 derniers jours et
   le dernier résultat de chaque travail. Une lecture refusée ou incomplète ne remplace rien. Un
   serveur à adresse interne demande à un administrateur d'autoriser les adresses internes dans la
   page de configuration du plugin, et son certificat doit être reconnu par le serveur GLPI.
4. Pour les autres outils (Acronis, Commvault, Synology Active Backup, Proxmox Backup Server…),
   exportez en CSV la liste des machines protégées avec la date de leur dernière sauvegarde, puis
   importez-la dans **Outils de sauvegarde**. Colonnes reconnues : machine (`host`, `vm`, `device`,
   `client`, `backup-id`…), `ip`, travail, dernière réussite (`last success`, `last backup`,
   `backup-time`), date d'exécution et résultat. Un journal avec une ligne par exécution convient :
   la plus récente de chaque machine compte. Un nouvel import du même outil remplace le précédent.
5. La liste **Actifs qui doivent être sauvegardés** commence par ceux qui n'ont aucune sauvegarde,
   puis ceux dont la sauvegarde est trop ancienne, puis ceux dont la dernière tentative a échoué.
   Elle donne l'ancienneté admise et son origine, et le dernier test de restauration repris des
   exercices de reprise : enregistrez le test sur le processus (onglet « Stratégie de reprise ») en
   désignant l'actif testé. Les machines sauvegardées que GLPI ne connaît pas sont listées à part.
6. Sur la fiche des mesures A.8.13 et A.5.30, le cadre « Couverture des sauvegardes » montre le taux
   de sauvegardes récentes, les actifs critiques sans sauvegarde récente, les échecs et les actifs
   critiques sans test de restauration récent. Les indicateurs de risque proposent la source
   « Actifs critiques sans sauvegarde récente (%) ».
