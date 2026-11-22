### Changed

- **Sécurité des fournisseurs rangée dans Gestion** (#1040) : nouvelle rubrique **Gestion >
  Sécurité des fournisseurs**, juste après Fournisseurs, avec sa page d'accueil (risques
  fournisseurs à réévaluer, écrans en cartes). Elle regroupe le registre des risques fournisseurs
  (venu de la rubrique Risques du menu GRC), les modèles de questionnaire fournisseur (autrefois
  sans entrée de menu) et le registre d'informations DORA (venu des mesures de sécurité, montré
  seulement si le référentiel DORA est activé). Les adresses des pages ne changent pas, les droits
  non plus.
- **Lien sécurisé du fournisseur réglé dans la configuration seulement** (#1040) : l'autorisation
  de répondre par lien sécurisé s'enregistre depuis Configuration > Plugins > GRC Manager, onglet
  Général ; la page de relecture des réponses ne l'enregistre plus elle-même. Un ancien envoi
  reste accepté pendant une version et mène à l'onglet.

### Added

- **Onglet Sécurité sur la fiche Fournisseur** (#1040) : les risques fournisseurs du fournisseur,
  leur niveau, la dernière évaluation (score et statut), la prochaine réévaluation (signalée si
  elle est dépassée), un bouton **Évaluer ce fournisseur** (risque prérempli) et l'accès à ses
  questionnaires. Lecture avec le droit des risques fournisseurs, dans les entités de la session.
