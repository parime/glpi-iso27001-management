### Fixed

- **Applications approuvées : erreur 500 pour un profil limité à certaines entités** (#1057) : le
  rapprochement avec l'inventaire logiciel restreignait les installations sur une colonne
  `is_recursive` que leur table n'a pas ; la restriction porte maintenant sur l'entité seule. Test
  d'intégration ajouté avec un profil en lecture limité à une entité (page, inventaire, onglet
  « Applications autorisées » des préférences, tâche de revue).
