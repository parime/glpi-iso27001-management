### Lire la supervision Zabbix (mesures A.8.16, A.8.6 et A.5.30)

L'auditeur demande comment les serveurs et services sont surveillés, s'ils sont disponibles comme
prévu et si leur capacité est suivie. Le plugin lit votre serveur **Zabbix en lecture seule** et
rapproche ses hôtes des ordinateurs et matériels réseau de GLPI ; il ne modifie jamais rien dans
Zabbix. Il faut le droit du plugin et la lecture native des ordinateurs.

1. Dans Zabbix, préparez un compte de lecture : Utilisateurs > Rôles utilisateur, un rôle de type
   Utilisateur dont l'API est limitée à host.get, problem.get, event.get, service.get, sla.get et
   sla.getsli ; Utilisateurs > Utilisateurs, un utilisateur de ce rôle dans un groupe qui a la
   lecture des groupes d'hôtes à superviser ; Utilisateurs > Jetons d'API, un jeton pour lui.
2. Ouvrez Sécurité technique > **Supervision Zabbix**. Dans **Connexion à Zabbix (lecture
   seule)**, saisissez l'adresse de l'interface Zabbix (par exemple
   `https://zabbix.exemple.test/zabbix`) et le jeton, choisissez l'entité des lectures puis
   enregistrez. Le jeton est chiffré par GLPI et n'est jamais réaffiché. Un serveur sur une adresse
   interne demande d'autoriser les adresses internes (Configuration du plugin > Connexions
   sortantes).
3. Cliquez sur **Lire maintenant**. Une lecture en échec ne remplace pas la précédente ; la case
   « Lire automatiquement chaque jour » active la tâche automatique « zabbix », désactivée par
   défaut.
4. Dans **Quels actifs doivent être supervisés ?**, choisissez les actifs concernés (actifs
   critiques, serveurs et matériels réseau, par défaut) et la période des problèmes.
5. Les compteurs donnent le taux d'actifs supervisés, les **actifs critiques non supervisés**, les
   problèmes ouverts de gravité haute, la disponibilité de service la plus faible et les actifs
   saturés (disque, mémoire, processeur). La liste des actifs montre pour chacun sa supervision, sa
   disponibilité mesurée, ses problèmes ouverts et son plus long arrêt comparé au RTO des processus
   métier qui en dépendent.
6. Dans **Problèmes des N derniers jours**, un problème de gravité haute peut être **proposé comme
   incident de sécurité** ou devenir un **ticket** : il n'est créé que sur votre clic.
7. Dans **Disponibilité des services (SLA Zabbix)**, rattachez chaque service au processus métier
   qu'il porte. Les hôtes Zabbix non rapprochés d'un actif sont listés à part.
8. Sur la fiche des mesures A.8.16, A.8.6 et A.5.30, le cadre « Supervision Zabbix » montre la
   couverture, les saturations ou la disponibilité, avec la date de la lecture. Les ordinateurs,
   matériels réseau et processus métier ont un onglet « Supervision Zabbix », et les indicateurs de
   risque proposent trois sources : disponibilité des services du mois, problèmes ouverts de
   gravité haute, actifs critiques non supervisés.
