### Added

- **Contrôleurs de gestion des serveurs, lot 1 : Redfish en lecture seule et import CSV** (issue
  #843, mesures A.8.2, A.8.9 et A.8.22). Nouvelle page « Contrôleurs de gestion des serveurs » dans
  la rubrique Sécurité technique (droit du plugin et lecture des ordinateurs, entités de la session).
  Chaque contrôleur (HPE iLO, Dell iDRAC, Lenovo XClarity Controller, Supermicro) est déclaré avec
  son adresse https et un compte local en lecture seule ; le mot de passe est chiffré par GLPI,
  jamais réaffiché, et doit être ressaisi si l'adresse ou le compte changent. Lecture Redfish sur
  clic, uniquement par des requêtes GET (aucune session, aucune action, aucun réglage modifié),
  limitée à l'adresse configurée (liens renvoyés suivis seulement sous `/redfish/v1`) ; certificat
  auto-signé accepté seulement si l'option, désactivée par défaut, est cochée pour ce contrôleur.
  Une lecture en échec ne remplace pas la précédente. Inventaire (modèle, firmware du contrôleur,
  BIOS, numéro de série, santé), rapprochement avec l'ordinateur de GLPI par le numéro de série ou
  choisi à la main, onglet « Contrôleur de gestion » sur l'ordinateur, serveurs physiques sans
  contrôleur connu. Écarts signalés seulement : IPMI sur le réseau, Telnet, SNMP v1/v2c, protocoles
  TLS anciens (import), certificat auto-signé, expiré ou proche de l'expiration, démarrage sécurisé
  désactivé, compte par défaut actif, adresse hors des réseaux d'administration déclarés dans la
  matrice des flux, santé dégradée. Comptes locaux et leur rôle listés pour la revue des accès
  privilégiés. Import CSV des contrôleurs sans Redfish (IPMI seul). Preuve sur les fiches des mesures
  A.8.2, A.8.9 et A.8.22, source de KRI « Contrôleurs de gestion des serveurs avec un écart grave ».
  `Http::send()` accepte une option explicite qui désactive la vérification du certificat, utilisée
  seulement pour ces contrôleurs. Tests contre un faux Redfish, tests d'intégration, matrice d'accès
  et contrôle XSS.
