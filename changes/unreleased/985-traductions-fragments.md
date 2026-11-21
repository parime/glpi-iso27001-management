### Changed

- **Traductions des PR en fragments** (issue #985) : une PR ajoute ses textes dans
  `locales/unreleased/<issue>-<sujet>.<langue>.po` au lieu de modifier les fichiers de langue
  complets et le `.pot`, que toutes les branches touchaient. La publication les range dans les
  `.po` (`tools/build_locales.py --release`) et régénère le `.pot` ; la CI vérifie que chaque
  chaîne du code est traduite. Rien ne change pour l'utilisateur.
