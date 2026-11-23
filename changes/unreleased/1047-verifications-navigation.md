### Changed

- **Nouveau menu : déconnectez-vous puis reconnectez-vous** (navigation simplifiée #1035, #1047).
  GLPI garde le menu en mémoire pendant la session : les nouvelles rubriques du menu GRC et les
  entrées ajoutées aux menus Assistance, Parc et Gestion n'apparaissent qu'à la connexion suivante.
  Les adresses des écrans et les droits ne changent pas.
- **Audit de l'Active Directory dans « Accès et comptes »** (#1047) : l'audit de l'Active Directory
  et d'Entra ID (rapports PingCastle, chemins d'attaque) porte sur les comptes et leurs droits ; il
  quitte Parc > Sécurité du parc pour la rubrique GRC & Conformité > Accès et comptes. Son adresse ne
  change pas.
- **Délégation pendant une absence : réglage enregistré par la configuration** (#1047) : le choix
  des décisions délégables (Configuration > Plugins > GRC Manager, onglet Personnes et politiques)
  est enregistré par la page de configuration et non plus par la page du journal des décisions
  déléguées. L'ancien envoi reste accepté pendant une version.

### Documentation

- **Tutoriel « Où est passé… ? »** (#1047) : un tableau, en français et en anglais, de l'ancien et du
  nouvel emplacement de chaque écran déplacé par la nouvelle navigation (menu GRC, Assistance, Parc,
  Gestion et configuration du plugin en onglets).
