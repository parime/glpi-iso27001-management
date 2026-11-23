# Tests de sécurité de bout en bout

Lancés par la CI (job d'installation réelle) dans une instance GLPI jetable :

| Script | Rôle |
|---|---|
| `setup.php` | crée le jeu de données (deux entités A et B, profils limités) et affiche les identifiants en JSON ; `setup.php xss '<json>'` crée les données piégées |
| `access-matrix.sh` | matrice de contrôle d'accès, une fois par profil (`none`, `reader`, `limited`, `general`, `admin`) |
| `xss-check.sh` | vérifie qu'aucune page ne restitue la charge piégée sans échappement |
| `js-errors.py` | ouvre l'accueil et chaque page de `front/` dans Chromium (Playwright) : aucune erreur JavaScript ni boîte de dialogue |
| `a11y-check.py` | axe-core sur des pages clés, en thème clair puis sombre (`a11y-palette.php`) : aucune violation grave des règles d'accessibilité déjà corrigées (#663) |
| `old-urls.py` | anciennes adresses (`old-urls.txt`) et anciennes ancres de la configuration (`config.php#<bloc>`, carte `data-anchors`) redirigées vers leur nouvelle place, avec un fil d'Ariane, en super-admin puis en lecteur (#1050) |
| `purge.php` | supprime le jeu de données (entités et comptes « Securite ISO … », objets nommés « … securite ») d'une instance de test partagée ; `--dry-run` compte sans supprimer |
| `not-covered.txt` | pages de `front/` volontairement non testées, avec la raison |

## Parcours de toutes les pages et anciennes adresses (#1050)

Le parcours de toutes les pages est celui de la sécurité commune
([glpi-plugin-security](https://github.com/parime/glpi-plugin-security), `checks/check-pages.sh`
et `checks/crawl-pages.py`), lancé par `.github/workflows/security.yml` sur chaque PR, en GLPI 11 et
12, avec l'administrateur et le lecteur de l'entité A (`pages-accounts`). Il découvre les pages
seul (menus, onglets, sous-pages, `front/*.php`, fiches) et signale les codes 404, 500 et 403 à
tort, les pages blanches, les erreurs PHP et JavaScript, le fil d'Ariane absent ou différent du
menu, et les écrans qu'aucun menu n'atteint. Une nouvelle page est parcourue sans rien modifier.

Les anciennes adresses sont vérifiées par `old-urls.py` (job d'installation réelle de `ci.yml`,
GLPI 11 et 12). Une page déplacée ajoute sa ligne à `old-urls.txt` ; une ancre de la
configuration déplacée n'a rien à ajouter (le script lit la carte `data-anchors` de la page).

Lancement local, sur une instance où le plugin et le jeu de données (`setup.php`) sont installés :

```sh
# Anciennes adresses (conteneur GLPI « glpi », image Playwright de la CI)
bash .github/scripts/security-e2e.sh old-urls
# ou, avec Playwright installé sur le poste :
python3 tests/security/old-urls.py http://localhost:8080 glpi:glpi 'iso-lecteur:Secrev#2026'

# Parcours de toutes les pages (clone de glpi-plugin-security à côté du plugin)
GLPI_CONTAINER=glpi bash ../glpi-plugin-security/checks/check-pages.sh . grcmanager ''   'php plugins/grcmanager/tests/security/setup.php' error 3000 'glpi:glpi iso-lecteur:Secrev#2026'
```

## Ajouter les tests d'une fonctionnalité : un nouveau fichier par script

Ne pas allonger les quatre scripts ci-dessus : toutes les branches les modifient en même temps et
entrent en conflit. Une fonctionnalité ajoute ses propres fichiers, nommés `<issue>-<sujet>`
(exemple complet : `852-mfa-coverage`) :

| Dossier | Chargé par | Contenu |
|---|---|---|
| `setup.d/<issue>-<sujet>.php` | `setup.php` (jeu de données) | données des entités A et B ; ajoute ses identifiants à `$ids` |
| `setup-xss.d/<issue>-<sujet>.php` | `setup.php xss` | données piégées avec `$canary` ; ajoute ses identifiants à `$out` |
| `access-matrix.d/<issue>-<sujet>.sh` | `access-matrix.sh` | contrôles par profil (`case $ROLE in ...`) |
| `xss-check.d/<issue>-<sujet>.sh` | `xss-check.sh` | `check "<libellé>" "$P/<page>"` |

Les fragments sont chargés à la fin du script, dans l'ordre des noms, et voient ses variables et
fonctions :

- `setup.php` : `$DB`, `$ids`, `$a` et `$b` (entités), `$add($objet, $champs)`. Le script est
  relancé plusieurs fois, aussi sur une instance déjà utilisée (la CI le lance deux fois de suite
  et exige les mêmes identifiants et le même nombre de lignes) : `$add` et
  `$insertOnce($table, $ligne)` retrouvent l'objet ou la ligne existante (nom, titre, code, type,
  entité, clés étrangères) avant de créer ; un autre appel vérifie d'abord que ses données
  n'existent pas (`countElementsInTable`, `getFromDBByCrit`).
- `setup.php xss` : `$DB`, `$ids` (jeu de données), `$out`, `$canary`, `$insert($table, $ligne)`,
  `$update($table, $ligne, $id)` (tables sans le préfixe `glpi_plugin_grcmanager_`).
- `access-matrix.sh` : `$ROLE`, `$P` (URL de `front/`), `id <clé>`, `code`, `body`, `csrf`,
  `expect <libellé> <attendu> <obtenu>`, `contains <libellé> <texte> oui|non <contenu>`,
  `tab <itemtype> <onglet> <id>`, `readable <pages...>` (refusées sans droit, ouvertes au lecteur
  et au super-admin, comme la liste `READABLE`), `$SEE_B`, `$DENY` et `$IS_ADMIN`. Les fragments
  sont chargés pour **chaque** profil (`none`, `reader`, `general`, `limited`, `admin`), même ceux
  dont la partie commune s'arrête plus tôt (issue #1079). Le journal donne le nombre de cas
  exécutés par profil (`INFO profil reader : N cas executes, dont M dans les fragments`), et la
  matrice échoue si un fragment nomme le profil courant (`reader)`, `limited|admin)`,
  `[ "$ROLE" = none ]`) sans y exécuter aucun cas.
- `xss-check.sh` : `$P`, `id`, `xid`, `check <libellé> <url>`, `tab`.

Le contrôle commun de couverture (glpi-plugin-security v1.9.1 et suivantes) trouve le chemin d'une
page dans `access-matrix.d/` : une page testée dans un fragment n'a pas à figurer dans la liste
`READABLE` d'`access-matrix.sh` (appeler `readable front/<page>.php` dans le fragment).
