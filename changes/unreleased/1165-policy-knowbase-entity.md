### Fixed

- **Ouverture d'une politique ou d'une procédure d'exploitation** (issue #1165). Avec un profil qui
  ne voit pas toutes les entités, la fiche plantait sur une erreur SQL (« Unknown column
  glpi_plugin_grcmanager_policies.entities_id ») : l'onglet natif « Base de connaissances » de GLPI
  11 et 12 filtre toujours sur l'entité de l'objet lié. La bibliothèque reçoit les colonnes attendues
  avec des valeurs fixes (entité racine, récursive) et reste commune à toutes les entités.
