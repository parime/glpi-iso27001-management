[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)

## 🇫🇷 Français

### Ajouter une fonctionnalité sans conflit

Plusieurs fonctionnalités sont développées en même temps, sur des branches tirées de `dev`. Quand
chacune ajoute des lignes à la même liste centrale, presque chaque PR entre en conflit avec les
autres. La règle est donc : **une nouvelle fonctionnalité s'ajoute en créant de nouveaux fichiers**,
découverts et assemblés automatiquement, sans modifier les listes centrales.

| Pour ajouter... | Créer | Ne plus modifier |
|---|---|---|
| ses tables (installation, mise à jour, désinstallation) | `src/Install/<Fonctionnalité>Tables.php` qui implémente `FeatureTables` | `src/Install/Installer.php` (`install()`, `uninstall()`, `SRC_ONLY_CLASSES`) |
| l'aide « À quoi sert cette page ? » | `src/Feature/<Fonctionnalité>Feature.php` qui implémente `PageHelpProvider` | `src/PageHelp.php` |
| une carte du tableau de bord | le même fichier, `DashboardCardsProvider` | `hook.php` (`plugin_grcmanager_dashboard_cards`) |
| une source de KRI | le même fichier, `RiskIndicatorSourcesProvider` | `src/RiskIndicator.php` (`SOURCES`, `getSources()`, `computeSourceValue()`) |
| une preuve sur la fiche d'une mesure | le même fichier, `ControlEvidenceProvider`, et son gabarit `templates/_<sujet>_evidence.html.twig` | `src/Control.php`, `templates/control_form.html.twig` |
| ses tests de bout en bout | `tests/security/setup.d/`, `setup-xss.d/`, `access-matrix.d/`, `xss-check.d/` : `<issue>-<sujet>.php` ou `.sh` | `tests/security/setup.php`, `access-matrix.sh`, `xss-check.sh` |
| son entrée de CHANGELOG | `changes/unreleased/<issue>-<sujet>.md` | `CHANGELOG.md` |
| une étape du tutoriel | `docs/tutorial/fr/NNNN-mots.md` et `docs/tutorial/en/NNNN-mots.md` | `docs/TUTORIAL.md` |

Restent des listes partagées, à toucher le moins possible et seulement en ajoutant des lignes :
`setup.php` (enregistrement des classes, droits), `src/Menu.php` (une page s'ajoute en onglet d'une
rubrique existante), `grcmanager.xml`, `README.md` / `README.en.md`, `phpstan.neon.dist`, `locales/`
(résolus automatiquement). Les fichiers propres à la fonctionnalité (sa classe, sa page `front/`,
ses gabarits, ses tests unitaires et d'intégration) sont nouveaux et ne gênent personne.

#### Tables : `src/Install/<Fonctionnalité>Tables.php`

```php
final class SupplierCertificateTables implements FeatureTables
{
    public static function install(string $keySign, string $charset, string $collation): void { /* CREATE TABLE si absente */ }
    public static function migrate(Migration $migration): void { /* colonnes ajoutées ensuite ; vide sinon */ }
    public static function uninstall(Migration $migration): void { $migration->dropTable(self::CERTIFICATES); }
    public static function pluginClasses(): array { return [\GlpiPlugin\Grcmanager\SupplierCertificate::class]; }
}
```

- `FeatureTablesRegistry` découvre ces classes (ordre alphabétique des fichiers) ; elles sont
  installées et migrées à chaque installation ou mise à jour, supprimées à la désinstallation.
  `pluginClasses()` liste les classes dont GLPI garde des lignes (historique, préférences,
  cibles de notification) : elles sont purgées à la désinstallation.
- `install()` et `migrate()` sont rejouables. Pas de SQL brut ailleurs que dans ces classes.
- Un test unitaire vérifie que chaque `*Tables` est installée une seule fois (découverte ou citée
  dans `Installer.php`, jamais les deux).

#### Descripteur : `src/Feature/<Fonctionnalité>Feature.php`

Un seul fichier par fonctionnalité, qui implémente les interfaces dont elle a besoin
(exemple complet : `src/Feature/MfaCoverageFeature.php`) :

- `PageHelpProvider::pageHelp()` : `['<clé de PageHelp::show()>' => ['purpose' => ..., 'expected' => ..., 'reference' => ...]]`.
- `DashboardCardsProvider::dashboardCards()` : cartes au format de GLPI, sans `group`. Le
  fournisseur `SecuredDashboardCards::xxx` passe par `canShow()` ; un autre fournisseur refuse
  lui-même toute donnée sans droit.
- `RiskIndicatorSourcesProvider::riskIndicatorSources()` : `label`, `unit`, `direction`,
  `rights`, `global`, `compute` (reçoit les entités du périmètre ; appelé aussi par la tâche
  planifiée, sans session).
- `ControlEvidenceProvider` : `controlEvidenceTitle()`, `controlEvidenceTemplate()`,
  `controlEvidence($code)` (null si la mesure n'est pas concernée ou sans droit). Le gabarit
  reçoit `evidence` ; le cadre s'affiche après les cadres existants.
- Clé facultative `after` (cartes, sources de KRI) : clé que l'entrée suit dans la liste ; sinon,
  elle va à la fin. Les listes existantes gardent leur ordre.

#### Tests de bout en bout : `tests/security/*.d/`

Voir `tests/security/README.md` : variables et fonctions disponibles dans chaque fragment
(`readable` pour les pages consultables). Un fragment de `setup.d/` ne recrée pas ce qui existe
déjà (le script est relancé). Le contrôle commun de couverture (glpi-plugin-security v1.9.1)
trouve le chemin d'une page dans `access-matrix.d/` : il n'y a plus à citer la page dans la liste
`READABLE` de `access-matrix.sh`.

#### CHANGELOG : `changes/unreleased/<issue>-<sujet>.md`

```markdown
### Added

- **Certificats des fournisseurs** (issue #123, mesure A.5.19). Ce que voit l'utilisateur...
```

Rubriques : `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`, `Documentation`.
`python3 tools/build_changelog.py --stdout` montre le résultat ; la CI vérifie le format. À la
publication, `release_bump.py` range les fragments dans la section de la version et les supprime.

#### Tutoriel : `docs/tutorial/{fr,en}/NNNN-mots.md`

- Une étape = un fichier par langue, **du même nom** dans `docs/tutorial/fr/` et
  `docs/tutorial/en/`, par exemple `0440-follow-supplier-certificates.md`.
- Le nom commence par quatre chiffres qui fixent l'ordre : prenez un nombre plus grand que le
  dernier fragment (les numéros vont de 10 en 10, ce qui laisse de la place pour insérer une étape
  entre deux autres). Deux branches qui prennent le même nombre ne se gênent pas : les noms
  diffèrent par la suite, et l'ordre reste stable.
- Le fichier commence par `### <titre de l'étape>`, **sans numéro** : « Étape 12 : » et
  « Step 12: » sont ajoutés à l'assemblage. Pour citer une autre étape, écrire
  `{{step:0030-configure-probability-x-impact-matrix}}` (nom du fichier sans `.md`), remplacé par
  son numéro.
- Les images restent relatives à `docs/` : `![...](screenshots/xxx.png)`.
- `docs/TUTORIAL.md` est **généré** par `python3 tools/build_tutorial.py`, à la publication de la
  version (outil de publication). Une PR ne le modifie pas ; la CI refuse une modification qui ne
  correspond pas aux fragments. Pour relire le résultat : `python3 tools/build_tutorial.py --stdout`.
  La CI vérifie aussi qu'il ne manque aucune langue et que chaque `{{step:...}}` existe
  (`--check`).
- Branche commencée avant les fragments, qui a ajouté son étape dans `TUTORIAL.md` : après avoir
  ramené `dev`, `python3 tools/build_tutorial.py --import` crée les fragments des étapes écrites à
  la main et réassemble le fichier (relire le nom et le numéro du fragment créé).

## 🇬🇧 English

### Adding a feature without conflicts

Several features are developed at the same time, on branches taken from `dev`. When each one adds
lines to the same central list, nearly every pull request conflicts with the others. Hence the
rule: **a new feature is added by creating new files**, discovered and assembled automatically,
without changing the central lists.

| To add... | Create | No longer edit |
|---|---|---|
| its tables (install, update, uninstall) | `src/Install/<Feature>Tables.php` implementing `FeatureTables` | `src/Install/Installer.php` (`install()`, `uninstall()`, `SRC_ONLY_CLASSES`) |
| the "What is this page for?" help | `src/Feature/<Feature>Feature.php` implementing `PageHelpProvider` | `src/PageHelp.php` |
| a dashboard card | the same file, `DashboardCardsProvider` | `hook.php` (`plugin_grcmanager_dashboard_cards`) |
| a KRI source | the same file, `RiskIndicatorSourcesProvider` | `src/RiskIndicator.php` (`SOURCES`, `getSources()`, `computeSourceValue()`) |
| evidence on a control form | the same file, `ControlEvidenceProvider`, and its template `templates/_<topic>_evidence.html.twig` | `src/Control.php`, `templates/control_form.html.twig` |
| its end-to-end tests | `tests/security/setup.d/`, `setup-xss.d/`, `access-matrix.d/`, `xss-check.d/`: `<issue>-<topic>.php` or `.sh` | `tests/security/setup.php`, `access-matrix.sh`, `xss-check.sh` |
| its CHANGELOG entry | `changes/unreleased/<issue>-<topic>.md` | `CHANGELOG.md` |
| a tutorial step | `docs/tutorial/fr/NNNN-words.md` and `docs/tutorial/en/NNNN-words.md` | `docs/TUTORIAL.md` |

Shared lists remain, to be touched as little as possible and only by adding lines: `setup.php`
(class registration, rights), `src/Menu.php` (a page is added as a tab of an existing section),
`grcmanager.xml`, `README.md` / `README.en.md`, `phpstan.neon.dist`, `locales/` (resolved
automatically). The feature's own files (its class, its `front/` page, its templates, its unit and
integration tests) are new and bother nobody.

- **Tables**: `FeatureTablesRegistry` discovers the classes implementing `FeatureTables`
  (alphabetical order of the files): installed and migrated at every install or update, dropped
  at uninstall; `pluginClasses()` lists the classes whose core rows are purged at uninstall.
- **Descriptor** `src/Feature/<Feature>Feature.php` (full example: `MfaCoverageFeature.php`):
  `PageHelpProvider`, `DashboardCardsProvider` (a `SecuredDashboardCards::xxx` provider goes
  through `canShow()`, any other provider denies by itself), `RiskIndicatorSourcesProvider`
  (`label`, `unit`, `direction`, `rights`, `global`, `compute`), `ControlEvidenceProvider` (the
  template receives `evidence`, the box is shown after the existing ones). Optional `after` key
  (cards, KRI sources): the key the entry follows in the list, otherwise at the end.
- **End-to-end tests**: see `tests/security/README.md`. A `setup.d/` fragment does not recreate
  what already exists. The common coverage check (glpi-plugin-security v1.9.1) finds a page path
  in `access-matrix.d/`.
- **CHANGELOG**: sections `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`,
  `Documentation`; `python3 tools/build_changelog.py --stdout` shows the result; at release,
  `release_bump.py` moves the fragments into the version section and deletes them.
- **Tutorial**: one file per language with the same name, starting with four digits (by tens)
  that set the order, first line `### <title>` without a number; `{{step:<file name>}}` refers to
  another step; images relative to `docs/`. `docs/TUTORIAL.md` is generated by
  `python3 tools/build_tutorial.py` at release; CI rejects a hand edit (`--stdout` to review,
  `--import` to turn steps written by hand into fragments).
