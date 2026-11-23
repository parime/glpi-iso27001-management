### Added

- **Fin de support : correspondances proposées** (issue #1056, mesures A.8.8 et A.5.9). Sur
  **Parc > Sécurité du parc > Fin de vie et correctifs > Logiciels à surveiller**, les noms de
  l'inventaire qui ne sont reliés à aucun produit endoflife.date reçoivent une proposition, tirée
  du catalogue de l'API v1 (nom et alias des produits, identifiants CPE et purl). Rien n'est
  appliqué sans validation : on coche les propositions justes et on les accepte (elles deviennent
  des correspondances manuelles), on refuse les autres (elles ne sont plus proposées). Égalité
  exacte uniquement, jamais de ressemblance ; un terme trop vague ne donne rien. Le catalogue est
  relu au plus une fois par semaine, par « Mettre à jour maintenant » ou la tâche quotidienne.
