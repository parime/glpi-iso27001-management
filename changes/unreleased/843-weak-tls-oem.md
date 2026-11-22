### Added

- Contrôleurs de gestion des serveurs, lot 4 (#843) : les protocoles anciens (SSLv3, TLS 1.0,
  TLS 1.1) acceptés par l'interface HTTPS du contrôleur sont lus par Redfish, en lecture seule, dans
  la politique de sécurité standard (SecurityPolicy) ou les extensions Oem des constructeurs :
  service de sécurité HPE iLO (versions TLS, mode de sécurité), attributs Dell iDRAC
  (`WebServer.1.TLSProtocol`), réglages TLS des rubriques Oem Lenovo XClarity, Supermicro et autres.
  L'écart « Protocoles anciens » n'a plus besoin de l'import CSV ; sans réglage lisible, il reste
  inconnu et l'import CSV le renseigne comme avant.
