### Fixed

- **Jeu de données des tests de sécurité relançable sur une instance déjà utilisée** (issue #1002).
  `tests/security/setup.php` et ses fragments retrouvent les objets et lignes existants avant de
  les créer : plus d'arrêt « Creation impossible » ni de doublon quand le script est relancé. La CI
  le lance deux fois de suite et exige les mêmes identifiants et le même nombre de lignes. Nouveau
  script `tests/security/purge.php` (`--dry-run` pour compter) qui retire le jeu de données d'une
  instance de test partagée, testé par la CI (purge puis nouvelle création).
