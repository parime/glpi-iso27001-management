### Added

- **Efficacité du traitement des risques** (issue #1182, ISO/IEC 27001 clauses 6.1.3, 8.3 et 9.1).
  - Onglet **Revues** sur chaque risque : une revue datée garde un instantané de la cotation (niveaux
    et scores initial et résiduel, risque cible, traitement, avancement du plan) avec le constat de
    la personne qui revoit, et fixe la prochaine date de revue. Une courbe montre l'évolution des
    niveaux d'une revue à l'autre. Une revue enregistrée n'est jamais modifiée.
  - **Risque cible** par risque (colonne de la liste) : une fois toutes les actions du plan de
    traitement terminées, une alerte apparaît sur la fiche si le risque résiduel reste au-dessus de
    la cible, ou s'il n'a pas été réévalué. Carte du tableau de bord « Plans de traitement terminés
    sans atteindre la cible ».
  - **Acceptation signée** : toute acceptation peut être signée, puis renouvelée, depuis la fiche du
    risque, avec une date d'expiration (12 mois par défaut). Le propriétaire du risque ne peut jamais
    la signer (séparation des tâches, contrôlée par le serveur, y compris pour l'acceptation formelle
    au-delà de l'appétence). Carte « Acceptations de risque à renouveler » (expirées ou à moins de
    30 jours), colonne « Acceptation valable jusqu'au ».
  - **Profils prêts à attribuer** (configuration, onglet Risques) : gestionnaire des risques,
    propriétaire de risque, responsable de traitement, comité des risques et audit interne (lecture
    seule), créés d'un clic avec les seuls droits du plugin nécessaires, sans aucun accès au parc.

### Changed

- Une acceptation formelle signée par la personne devenue propriétaire du risque ne vaut plus : elle
  doit être signée de nouveau par quelqu'un d'autre (issue #1182).
