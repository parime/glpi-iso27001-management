### Added

- **Échelle de conformité dans les questionnaires fournisseurs** (issue #1159, mesures A.5.19 à
  A.5.22). Chaque question d'un modèle a un type de réponse : « Oui / Non » (par défaut, celui de
  toutes les questions existantes, reprises sans changement) ou « Échelle de conformité » (Conforme,
  Partiellement conforme, Non conforme, Non applicable). La note compte Conforme pour 1 point,
  Partiellement conforme pour 0,5, Non conforme pour 0, multipliés par la pondération ; Non
  applicable est exclu du calcul. L'échelle s'affiche dans l'évaluation, sur la page du lien
  sécurisé, dans le fichier CSV exporté (dont les libellés sont reconnus au réimport) et dans
  l'aperçu avant enregistrement.
