### Fixed

- **Statut, urgence, impact et priorité des incidents de sécurité** (issue #951, suite de #796).
  Les listes Urgence et Impact d'un incident de sécurité étaient vides et son statut restait
  bloqué sur « Nouveau », même pour un super-admin : le formulaire de l'incident, ses champs
  prédéfinis dans un gabarit et l'aperçu du gabarit ne permettaient donc pas de les régler.
  Un incident suit maintenant les mêmes réglages que les tickets : masques d'urgence et d'impact
  et matrice de priorité de la configuration, cycle de vie des tickets du profil.
