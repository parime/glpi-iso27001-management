# Entrées de CHANGELOG à publier

Une PR ajoute son entrée de CHANGELOG ici, dans un **nouveau fichier** `<issue>-<sujet>.md`
(exemple : `123-supplier-certificates.md`), au lieu de modifier `CHANGELOG.md` :

```markdown
### Added

- **Certificats des fournisseurs** (issue #123, mesure A.5.19). Ce que voit l'utilisateur...
```

- Rubriques possibles : `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`,
  `Documentation` ; plusieurs dans un même fichier si besoin. Pas de titre `#` ni `##`.
- Relire le résultat : `python3 tools/build_changelog.py --stdout`. La CI vérifie le format
  (`--check`).
- À la publication, l'outil de publication (`release_bump.py`) range les fragments dans la section
  de la nouvelle version, rubrique par rubrique, et les supprime.
