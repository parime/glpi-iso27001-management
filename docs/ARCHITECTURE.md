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
| un réglage dans la page de configuration | `src/Config/Block/<Réglage>Block.php` (étend `ConfigBlock`) et son gabarit `templates/config/blocks/_<réglage>.html.twig` ; un onglet : `src/Config/Tab/<Onglet>Tab.php` | `front/config.php`, `templates/config_form.html.twig` |
| ses tests de bout en bout | `tests/security/setup.d/`, `setup-xss.d/`, `access-matrix.d/`, `xss-check.d/` : `<issue>-<sujet>.php` ou `.sh` | `tests/security/setup.php`, `access-matrix.sh`, `xss-check.sh` |
| son entrée de CHANGELOG | `changes/unreleased/<issue>-<sujet>.md` | `CHANGELOG.md` |
| une étape du tutoriel | `docs/tutorial/fr/NNNN-mots.md` et `docs/tutorial/en/NNNN-mots.md` | `docs/TUTORIAL.md` |
| ses traductions | `locales/unreleased/<issue>-<sujet>.<langue>.po`, une par langue de base | `locales/*.po`, `locales/grcmanager.pot`, `locales/*.mo` (non versionnés) |

Restent des listes partagées, à toucher le moins possible et seulement en ajoutant des lignes :
`setup.php` (enregistrement des classes, droits), `src/Menu.php` (une page s'ajoute en onglet d'une
rubrique existante), `grcmanager.xml`, `README.md` / `README.en.md`, `phpstan.neon.dist`. Les fichiers propres à la fonctionnalité (sa classe, sa page `front/`,
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

#### Réglage : `src/Config/Block/<Réglage>Block.php`

La page de configuration du plugin (Configuration > Plugins > GRC Manager) est faite d'onglets
(`general`, `frameworks`, `risks`, `controls`, `people`, `incidents`, `connectors`, `assets`),
chacun dans `src/Config/Tab/<Onglet>Tab.php` (`key()`, `label()`, `icon()`, `order()`), et de blocs
de réglages découverts par `ConfigTabs` (ordre alphabétique des fichiers). Un bloc étend
`ConfigBlock` :

```php
final class ZabbixBlock extends ConfigBlock
{
    public static function tab(): string { return 'connectors'; }
    public static function order(): int { return 40; }            // de 10 en 10 dans l'onglet
    public static function after(): string { return 'SentinelOne'; } // facultatif : suit ce bloc
    public static function template(): string { return '@grcmanager/config/blocks/_zabbix.html.twig'; }
    public static function anchors(): array { return ['grc-zabbix-config']; } // id du cadre
    public static function canView(): bool { return (bool) Session::haveRight(Installer::RIGHT_NAME, READ); }
    public static function data(): array { return ['zabbix' => ZabbixMonitoring::configData()]; }
    public static function handle(): bool
    {
        if (!isset($_POST['save_zabbix_settings'])) {
            return false;
        }
        Session::checkRight(Installer::RIGHT_NAME, UPDATE);
        // ... enregistrer, journal d'audit ...
        Html::back(); // revient sur l'onglet (?tab=connectors)
        return true;
    }
}
```

- La clé du bloc (citée par `after()`) est le nom de la classe sans « Block ».
- `data()` n'est appelé que si l'onglet du bloc est ouvert. Le gabarit reçoit aussi `csrf_token`,
  `can_edit_config` et `can_edit_sensitive` ; il n'affiche ses boutons qu'avec le droit de
  modifier. Le formulaire envoie vers `front/config.php`, dont `ConfigTabs::handlePost()`
  appelle `handle()` de chaque bloc.
- Un onglet n'est montré que si au moins un de ses blocs l'est (`canView()`). L'onglet ouvert est
  dans l'adresse : `front/config.php?tab=<clé>`. `ConfigTabs::anchorUrl('<id>')` donne l'adresse
  d'un bloc ; un ancien lien `config.php#<id>` ouvre son onglet (identifiants de `anchors()`).
- Réglage déplacé depuis une page d'exploitation : pendant une version, l'ancien POST de la page
  reste accepté et redirige vers `ConfigTabs::anchorUrl('<id>')` avec un message.
- Un test unitaire (`tests/Unit/Config/ConfigTabsTest.php`) vérifie que chaque bloc a un onglet
  connu et un gabarit, et que chaque lien `config.php#<id>` du code mène à un bloc.

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

#### Traductions : `locales/unreleased/<issue>-<sujet>.<langue>.po`

Un fichier par langue de base (`de_DE`, `en_GB`, `es_ES`, `fr_FR`, `it_IT`, `pl_PL`, `pt_BR`),
avec les mêmes `msgid` et seulement les entrées de la PR (modèle :
[locales/unreleased/README.md](../locales/unreleased/README.md)). Les variantes régionales suivent
leur langue de base. `python3 tools/build_locales.py --check` vérifie les fragments ; la CI vérifie
en plus que chaque chaîne du code est dans le `.pot` versionné ou dans un fragment. Les `.mo` ne
sont pas versionnés : `python3 tools/build_locales.py` les compile (`.po` + fragments) pour la CI,
le déploiement et l'archive publiée. À la publication, `release_bump.py` appelle
`build_locales.py --release` : fragments rangés dans les `.po` (`msgcat`) puis supprimés, `.pot`
régénéré, variantes resynchronisées, `.mo` compilés.

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
| a setting in the configuration page | `src/Config/Block/<Setting>Block.php` (extends `ConfigBlock`) and its template `templates/config/blocks/_<setting>.html.twig`; a tab: `src/Config/Tab/<Tab>Tab.php` | `front/config.php`, `templates/config_form.html.twig` |
| its end-to-end tests | `tests/security/setup.d/`, `setup-xss.d/`, `access-matrix.d/`, `xss-check.d/`: `<issue>-<topic>.php` or `.sh` | `tests/security/setup.php`, `access-matrix.sh`, `xss-check.sh` |
| its CHANGELOG entry | `changes/unreleased/<issue>-<topic>.md` | `CHANGELOG.md` |
| a tutorial step | `docs/tutorial/fr/NNNN-words.md` and `docs/tutorial/en/NNNN-words.md` | `docs/TUTORIAL.md` |
| its translations | `locales/unreleased/<issue>-<topic>.<language>.po`, one per base language | `locales/*.po`, `locales/grcmanager.pot`, `locales/*.mo` (not versioned) |

Shared lists remain, to be touched as little as possible and only by adding lines: `setup.php`
(class registration, rights), `src/Menu.php` (a page is added as a tab of an existing section),
`grcmanager.xml`, `README.md` / `README.en.md`, `phpstan.neon.dist`. The feature's own files (its class, its `front/` page, its templates, its unit and
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
- **Configuration setting** `src/Config/Block/<Setting>Block.php` (extends `ConfigBlock`):
  `tab()` (`general`, `frameworks`, `risks`, `controls`, `people`, `incidents`, `connectors`,
  `assets`; a tab is `src/Config/Tab/<Tab>Tab.php`), `order()`, optional `after()` (key of the
  block it follows: class name without "Block"), `template()`
  (`templates/config/blocks/_<setting>.html.twig`), `anchors()` (HTML ids: old
  `config.php#<id>` links open its tab), `canView()`, `data()` (only computed when its tab is
  open; the template also receives `csrf_token`, `can_edit_config`, `can_edit_sensitive`) and
  `handle()` (POST to `front/config.php`: return false when not concerned, otherwise check the
  rights, save and `Html::back()`). A tab is shown when at least one of its blocks is; the open
  tab is in the URL (`front/config.php?tab=<key>`), `ConfigTabs::anchorUrl('<id>')` gives a
  block's URL. A setting moved from an operations page keeps accepting its old POST for one
  version, redirecting to that URL with a message.
- **End-to-end tests**: see `tests/security/README.md`. A `setup.d/` fragment does not recreate
  what already exists. The common coverage check (glpi-plugin-security v1.9.1) finds a page path
  in `access-matrix.d/`.
- **CHANGELOG**: sections `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`,
  `Documentation`; `python3 tools/build_changelog.py --stdout` shows the result; at release,
  `release_bump.py` moves the fragments into the version section and deletes them.
- **Translations**: one fragment per base language with the same `msgid`s, only the PR's
  entries; regional variants follow their base language. `python3 tools/build_locales.py --check`
  checks them; CI also checks every string of the code is in the versioned `.pot` or a fragment.
  The `.mo` are not versioned: `python3 tools/build_locales.py` compiles them (CI, deploy, release
  archive). At release, `build_locales.py --release` merges the fragments into the `.po`, deletes
  them, regenerates the `.pot`, resyncs the variants and compiles the `.mo`.
- **Tutorial**: one file per language with the same name, starting with four digits (by tens)
  that set the order, first line `### <title>` without a number; `{{step:<file name>}}` refers to
  another step; images relative to `docs/`. `docs/TUTORIAL.md` is generated by
  `python3 tools/build_tutorial.py` at release; CI rejects a hand edit (`--stdout` to review,
  `--import` to turn steps written by hand into fragments).
