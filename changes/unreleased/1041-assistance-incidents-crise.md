### Changed

- **Assistance > Incidents de sécurité et Crise et continuité** (#1041) : tout ce qui sert à
  traiter un incident ou une crise est dans le menu Assistance, à côté des tickets, des problèmes
  et des changements.
  - « Incidents de sécurité » est placé juste après « Changements » (et non plus après
    « Changements récurrents »). Ses liens d'en-tête deviennent des onglets de rubrique :
    Playbooks de remédiation, Import des alertes EDR, Journal d'audit de sécurité.
  - Nouvelle entrée « Crise et continuité », juste après : page d'accueil à cartes groupées
    (Se préparer, S'entraîner, Communiquer) et onglets Processus métier, Scénarios de crise
    sécurité, Exercices de crise, Programme des tests de reprise, Modèles de messages de crise.
    Les exercices, processus et scénarios quittent la rubrique « Opérations » du menu GRC ; les
    modèles de messages, l'en-tête des incidents. Fil d'Ariane « Assistance > … ».
  - SentinelOne : le bouton « Lire maintenant », le résultat de la dernière lecture et les agents
    sans ordinateur GLPI passent de la configuration à la page Import des alertes EDR, avec les
    alertes à examiner. L'onglet Connecteurs garde l'adresse, le jeton, l'entité, le test de
    connexion et la date de la dernière lecture. Un ancien envoi « Lire maintenant » depuis
    la configuration reste accepté pendant une version et ouvre la nouvelle page.
  - Les adresses des pages et les droits ne changent pas. Le menu étant gardé dans la session,
    la nouvelle organisation apparaît à la connexion suivante.
