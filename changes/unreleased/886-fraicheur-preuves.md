### Added

- **Fraîcheur des preuves sur un rythme récurrent** (issue #886, clause 7.5, mesure A.5.36).
  - Onglet « Validités des preuves » : un rythme de renouvellement par document de preuve, tous les
    N jours, semaines, mois ou ans, ou au dernier jour ouvré du mois ou du trimestre, en plus de la
    date de validité fixe. La prochaine échéance est calculée depuis la dernière collecte saisie,
    sinon depuis la dernière modification du document ; les jours ouvrés excluent les week-ends et
    les fermetures déclarées dans GLPI.
  - Une preuve non renouvelée à temps est signalée : badge de l'onglet, carte du tableau de bord
    « Preuves récurrentes non renouvelées à temps », contrôle de cohérence des preuves expirées et
    index du dossier de preuves pour l'auditeur.
  - Le responsable du renouvellement est prévenu une fois par échéance par la notification
    existante « Preuve à renouveler » (nouvelle balise `##evidence.nextdue##`). Aucune preuve,
    aucun statut, aucun ticket n'est modifié ou créé automatiquement.
