### Fixed

- **Comptes à privilèges : élément jamais modifié** (issue #1145). Bitwarden et Vaultwarden laissent
  `passwordRevisionDate` vide tant que le mot de passe n'a jamais été changé : l'import CSV retient
  alors la date de création de l'élément (`creationDate`) au lieu de signaler « date inconnue ».
  Constaté en vrai contre Vaultwarden (brique du laboratoire de test).
