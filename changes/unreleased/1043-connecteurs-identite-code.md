### Changed

- **Connecteurs d'identité et de code réglés dans la configuration** (#1043) : l'application
  Microsoft Entra ID / Microsoft Graph (locataire, identifiant, secret) et la connexion à GitHub et
  GitLab (jetons, serveurs, lecture automatique) se règlent désormais dans Configuration > Plugins >
  GRC Manager, onglet **Connecteurs** : un bloc par connecteur avec les secrets chiffrés par GLPI et
  jamais réaffichés, **Tester la connexion** (une demande de jeton pour Graph, une lecture sans
  donnée pour GitHub et GitLab) et la date de la dernière lecture. Les groupes d'accès distant de la
  couverture MFA passent dans l'onglet **Personnes et politiques**. Les pages Couverture MFA, Droits
  sur les partages et Sécurité du code gardent les résultats, la lecture sur clic et un bouton
  **Régler la connexion**. Un ancien envoi de réglage depuis ces pages reste accepté pendant une
  version et mène au bon onglet. Les droits ne changent pas.
