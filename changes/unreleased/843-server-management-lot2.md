### Added

- **Contrôleurs de gestion des serveurs, lot 2** (issue #843, mesures A.5.18, A.8.2 et A.8.8). Le
  firmware de chaque contrôleur (iLO, iDRAC, XClarity, IPMI) et le BIOS du serveur sont comparés aux
  vulnérabilités du parc et aux fins de support d'endoflife.date, comme les autres firmwares : mêmes
  alias et même veille, aucun nouvel appel extérieur. Quatre écarts s'ajoutent sur la page des
  contrôleurs (vulnérabilité prioritaire, vulnérabilités connues, firmware plus supporté, fin de
  support proche), et les firmwares des contrôleurs apparaissent sur les pages des vulnérabilités du
  parc et des fins de support, rattachés à leur serveur. Les comptes locaux des contrôleurs sont
  figés au lancement d'une campagne de revue des accès, dans un onglet **Comptes des contrôleurs de
  serveurs**, avec une suggestion (compte constructeur ou désactivé : retirer ; administrateur ou
  verrouillé : examiner) ; le gestionnaire de la campagne garde ou retire chaque compte, rien n'est
  changé sur les contrôleurs.
