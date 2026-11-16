[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)

## 🇫🇷 Français

**Contribuer à GLPI GRC Manager**

Merci de votre intérêt pour ce projet. Voir [docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md)
pour l'état d'avancement avant de proposer du code.

### Principes

- **Qualité avant rapidité** : pas de code temporaire, pas de raccourci fragile.
- **Aucune donnée métier en dur** : catégories, seuils, textes utilisateur : tout est configurable
  ou traduit, jamais codé en dur.
- **Sécurité native** : toute contribution touchant à l'authentification, aux permissions ou aux
  entrées utilisateur doit rester conforme aux droits GLPI dédiés du plugin.
- **Documentation obligatoire** : une fonctionnalité sans documentation n'est pas considérée
  terminée (voir [DEFINITION_OF_DONE.md](DEFINITION_OF_DONE.md)).

### Stratégie de branches

| Branche | Rôle |
|---|---|
| `main` | Version stable, releases uniquement. Protégée : PR + CI verte + review obligatoires. |
| `dev` | Développement courant, intégration continue des features. |
| `feature/*` | Nouvelle fonctionnalité, part de `dev`. |
| `bugfix/*` | Correction non urgente, part de `dev`. |
| `hotfix/*` | Correction urgente production, part de `main`. |

### Workflow

1. Ouvrir ou reprendre une issue décrivant le besoin.
2. Créer une branche `feature/*`, `bugfix/*` ou `hotfix/*` depuis la base appropriée.
3. Développer, avec tests et documentation associés.
4. Vérifier localement (`composer test`, `composer stan`, `composer cs`).
5. Ouvrir une Pull Request en suivant le [template](.github/pull_request_template.md).
6. La CI doit passer (syntaxe, qualité, sécurité, tests, installation réelle sur GLPI).
7. Revue de code obligatoire avant merge dans `dev`.
8. Les releases vers `main` sont gérées par les mainteneurs (voir [GOVERNANCE.md](GOVERNANCE.md)).

### Exigences par Pull Request

Une PR n'est acceptée que si elle inclut, quand applicable :

- code + tests (unitaires, installation réelle sur GLPI selon le périmètre) ;
- traductions FR/EN pour toute nouvelle chaîne utilisateur ;
- `locales/grcmanager.pot` régénéré si une chaîne change : `vendor/bin/extract-locales`, puis
  `git checkout -- locales/en_GB.po` (l'outil réécrit ce fichier) ; la CI échoue si le `.pot`
  versionné ne correspond plus au code. Lancer l'outil avec le gettext de GNU (Debian, Ubuntu,
  comme la CI) : celui d'Alpine (musl) perd une partie des chaînes ;
- migration idempotente dans `src/Install/Installer.php` si le modèle de données change ;
- entrée [CHANGELOG.md](CHANGELOG.md) ;
- capture d'écran si l'interface est modifiée.

### Code de conduite

Toute contribution est soumise au [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

### Signaler un bug de sécurité

**Ne pas passer par une issue publique.** Voir [SECURITY.md](SECURITY.md).

## 🇬🇧 English

**Contributing to GLPI GRC Manager**

Thank you for your interest in this project. See [docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md)
for the current progress before proposing code.

### Principles

- **Quality before speed**: no temporary code, no fragile shortcuts.
- **No business data hard-coded**: categories, thresholds, user-facing text: everything is
  configurable or translated, never hard-coded.
- **Security by design**: any contribution touching authentication, permissions or user input
  must stay consistent with the plugin's own dedicated GLPI rights.
- **Documentation is mandatory**: a feature with no documentation is not considered done (see
  [DEFINITION_OF_DONE.md](DEFINITION_OF_DONE.md)).

### Branch strategy

| Branch | Role |
|---|---|
| `main` | Stable version, releases only. Protected: PR + green CI + review required. |
| `dev` | Ongoing development, continuous integration of features. |
| `feature/*` | New feature, branches from `dev`. |
| `bugfix/*` | Non-urgent fix, branches from `dev`. |
| `hotfix/*` | Urgent production fix, branches from `main`. |

### Workflow

1. Open or pick up an issue describing the need.
2. Create a `feature/*`, `bugfix/*` or `hotfix/*` branch from the appropriate base.
3. Develop, with the associated tests and documentation.
4. Check locally (`composer test`, `composer stan`, `composer cs`).
5. Open a Pull Request following the [template](.github/pull_request_template.md).
6. CI must pass (syntax, quality, security, tests, real GLPI installation).
7. Code review is mandatory before merging into `dev`.
8. Releases to `main` are managed by the maintainers (see [GOVERNANCE.md](GOVERNANCE.md)).

### Requirements per Pull Request

A PR is only accepted if it includes, where applicable:

- code + tests (unit, real GLPI installation depending on scope);
- FR/EN translations for any new user-facing string;
- an idempotent migration in `src/Install/Installer.php` if the data model changes;
- a [CHANGELOG.md](CHANGELOG.md) entry;
- a screenshot if the interface is changed.

### Code of conduct

Every contribution is subject to the [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

### Reporting a security bug

**Do not go through a public issue.** See [SECURITY.md](SECURITY.md).
