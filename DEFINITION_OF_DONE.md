# Definition of Done

Une fonctionnalité ou un sprint n'est considéré terminé que si tous les critères applicables
ci-dessous sont remplis. Aucune étape n'est sautée : Analyse → Conception → Développement → Tests
→ Revue de code → Documentation → Validation (voir
[docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md)).

## Fonctionnel

- [ ] Le comportement correspond à l'issue/besoin décrit.
- [ ] Les cas limites et messages d'erreur ont été considérés.

## Technique

- [ ] Le code respecte les standards du projet (PSR-12, `phpcs.xml.dist`).
- [ ] Aucune donnée métier codée en dur (catégories, seuils, textes utilisateur : configurables ou
      traduits).
- [ ] Toute migration de schéma est idempotente (voir `src/Install/Installer.php`).
- [ ] Les listes affichent un lien cliquable vers `showForm()` et des valeurs d'énumération
      traduites/badgées, jamais une valeur brute non traduite.

## Qualité

- [ ] Tests unitaires ajoutés/mis à jour pour toute logique pure (`src/Services/`, `src/Compat/`).
- [ ] `composer test`, `composer stan`, `composer cs` passent localement.
- [ ] CI verte (syntaxe, PHPStan, PHPCS, PHPUnit, installation réelle sur GLPI, sécurité).

## Documentation

- [ ] `CHANGELOG.md` mis à jour.
- [ ] Traductions FR/EN pour toute nouvelle chaîne utilisateur (`locales/fr_FR.po`, `locales/en_GB.po`).
- [ ] Documentation utilisateur/développeur/architecture mise à jour si le comportement ou la
      structure change.

## Validation

- [ ] Vérifié contre une instance GLPI réelle (pas seulement supposé), écran par écran quand
      l'interface est concernée.
- [ ] Revue de code par au moins une autre personne (ou justification documentée si solo).
