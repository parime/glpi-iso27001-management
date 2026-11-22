### Added

- **Parcours de toutes les pages à chaque PR** (#1050) : `tests/security/crawl.py` ouvre, dans un
  vrai navigateur, en GLPI 11 et 12, avec un compte super-administrateur et un compte lecteur,
  chaque page du plugin découverte automatiquement (menus, sous-pages, `front/`, fiches existantes
  et onglets du plugin, y compris sur les écrans de GLPI qu'il modifie). La CI échoue sur une page
  en 404 ou 500, une page blanche, un refus à tort, une erreur PHP ou JavaScript, un fil d'Ariane
  absent ou différent de l'entrée de menu, ou une ancienne adresse non redirigée
  (`tests/security/crawl-redirects.txt`). Lancement local décrit dans `tests/security/README.md`.
