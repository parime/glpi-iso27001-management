### Added

- **Applications et services en ligne approuvés** (#622, mesure A.5.23) : sur chaque application de
  GLPI (Parc > Applications), un onglet « Usage autorisé » à côté de la fiche « Développement
  sécurisé » (#777), sans registre en double : statut (en évaluation, approuvée, approuvée avec
  restrictions, refusée), fournisseur et logiciel de GLPI liés, responsable, services utilisateurs,
  hébergement (en ligne ou sur site), dates de début et de fin d'usage, cas d'usage autorisés et
  restrictions, données les plus sensibles autorisées (échelle de confidentialité de la
  classification C/I/D, avec alerte si la classification de l'application la dépasse), dérogations
  accordées (registre des dérogations) et revue tous les N mois (12 par défaut). Nouvelle page
  « Applications approuvées » de la rubrique Risques : registre avec badges, indicateurs (décisions,
  revues en retard, fiches à compléter) et rapprochement avec l'inventaire logiciel (logiciels
  installés refusés ou encore en évaluation, logiciels hors registre les plus installés, filtre par
  catégorie), en lecture seule. Onglet « Applications autorisées » des préférences : la liste des
  applications approuvées et de celles à ne pas utiliser, lisible par tous les utilisateurs, même
  sans droit sur le plugin. Tâche automatique « approvedappreview » : à l'échéance de la revue, un
  ticket de réévaluation pour le responsable (un seul tant qu'il reste ouvert) ; aucun statut n'est
  changé automatiquement. Preuve sur la fiche de la mesure A.5.23.
