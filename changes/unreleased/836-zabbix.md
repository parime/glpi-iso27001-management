### Added

- **Connecteur Zabbix en lecture seule** (issue #836, mesures A.8.16, A.8.6 et A.5.30) : page
  « Supervision Zabbix » (rubrique Sécurité technique) :
  - lecture de l'API JSON-RPC du serveur Zabbix configuré (5.4 et suivants), sur clic ou par la
    tâche automatique « zabbix » désactivée par défaut ; liste fermée de méthodes de lecture
    (host.get, problem.get, event.get, sla.get, sla.getsli, service.get), seule l'adresse du serveur
    configuré est appelée ; jeton d'API chiffré par GLPI, jamais réaffiché ni journalisé ; une
    lecture en échec ne remplace pas la précédente ;
  - hôtes rapprochés des ordinateurs et matériels réseau (numéro de série, nom, nom court, adresse
    IP) : taux d'actifs supervisés, actifs critiques non supervisés, hôtes non rapprochés ;
  - disponibilité mesurée par actif (temps sans problème de gravité haute) et plus long arrêt
    comparé au RTO des processus métier dépendants ; disponibilité des services (SLA Zabbix)
    comparée au SLO, chaque service rattaché à un processus métier ;
  - problèmes de la période et problèmes ouverts (gravité, durée, acquittement), saturations de
    disque, mémoire ou processeur (A.8.6) ; un problème de gravité haute peut devenir un incident de
    sécurité (nouvelle origine « Supervision de l'infrastructure ») ou un ticket, sur clic seulement ;
  - onglet « Supervision Zabbix » sur les ordinateurs, matériels réseau et processus métier ; preuve
    sur les mesures A.8.16, A.8.6 et A.5.30 ; trois sources d'indicateurs de risque (disponibilité
    des services du mois, problèmes ouverts de gravité haute, actifs critiques non supervisés) ;
  - tables rejouables supprimées à la désinstallation ; tests contre un faux serveur Zabbix,
    matrice d'accès et contrôle XSS ; tutoriel (étape « Lire la supervision Zabbix »).
