### Suivre la disponibilité des services exposés et leurs certificats avec Uptime Kuma (mesures A.8.16, A.5.30 et A.8.24)

Uptime Kuma vérifie à intervalles réguliers que vos sites et services répondent, et relève la date
d'expiration de leurs certificats TLS. Le plugin le lit **en lecture seule** : rien n'est modifié dans
Uptime Kuma et aucun certificat n'est renouvelé.

1. Dans Uptime Kuma, menu **Settings > API Keys**, créez une clé (elle ne donne accès qu'à la page
   `/metrics`, en lecture). Vous pouvez aussi, ou à la place, lister des pages de statut publiques.
2. Dans GLPI, ouvrez **Sécurité technique > Services exposés (Uptime Kuma)**, cadre **Connexion à
   Uptime Kuma** : saisissez l'adresse (par exemple `https://status.example.test`), la clé d'API et,
   si besoin, les pages de statut (fin de leur adresse, par exemple `clients`), puis **Enregistrer** et
   **Lire maintenant**. Un serveur sur une adresse interne doit être autorisé dans la configuration du
   plugin (**Connexions sortantes**).
3. Le tableau **Services surveillés** montre chaque sonde : son état, sa disponibilité sur la période,
   l'actif GLPI reconnu (ordinateur, matériel réseau ou application, par le nom d'hôte surveillé) et la
   plus longue panne, comparée au RTO des processus métier qui dépendent de l'actif. Choisissez un
   processus métier dans la liste de la ligne pour une sonde sans actif.
4. Pour une panne longue, cliquez sur **Proposer comme incident de sécurité** : l'incident est créé,
   rattaché à l'actif ; le badge **Annoncer aux utilisateurs** ouvre ensuite sa communication de crise.
   Rien n'est créé sans votre clic.
5. Le cadre **Certificats TLS** classe les certificats expirés, non valides ou proches de l'échéance
   (seuil réglable dans **Seuils**). Pour être prévenu, créez un indicateur de risque « Certificats
   TLS à renouveler (Uptime Kuma) » avec un seuil d'alerte.
6. Le cadre **Actifs publiés sur Internet sans sonde** liste les actifs de la matrice des flux que
   personne ne surveille.
7. Pour des relevés réguliers (la disponibilité en est plus précise), cochez **Lire automatiquement
   toutes les heures** : la tâche automatique `uptimekuma` est désactivée après l'installation.
