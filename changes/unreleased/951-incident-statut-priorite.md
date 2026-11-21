### Fixed

- **Gabarits et champs des incidents de sécurité sur GLPI 11 et 12** (issue #951, suite de #796).
  - La liste des gabarits (bouton « Modèle » des incidents de sécurité) affichait « An unexpected
    error occurred » dès qu'un gabarit existait : GLPI ne retrouvait pas la classe à partir du nom
    raccourci des tables des gabarits. La liste s'affiche de nouveau et ouvre chaque gabarit.
  - Les listes Urgence et Impact d'un incident étaient vides et son statut restait bloqué sur
    « Nouveau », même pour un super-admin, dans le formulaire de l'incident comme dans les champs
    prédéfinis d'un gabarit. Un incident suit maintenant les mêmes réglages que les tickets :
    masques d'urgence et d'impact et matrice de priorité de la configuration, cycle de vie des
    tickets du profil.
  - Après l'ajout d'un champ masqué, obligatoire, prédéfini ou en lecture seule, on revient au
    gabarit au lieu d'arriver sur la fiche technique du champ.
