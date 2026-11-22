# Tests de sécurité de bout en bout

Lancés par la CI (job d'installation réelle) dans une instance GLPI jetable :

| Script | Rôle |
|---|---|
| `setup.php` | crée le jeu de données (deux entités A et B, profils limités) et affiche les identifiants en JSON ; `setup.php xss '<json>'` crée les données piégées |
| `access-matrix.sh` | matrice de contrôle d'accès, une fois par profil (`none`, `reader`, `limited`, `general`, `admin`) |
| `xss-check.sh` | vérifie qu'aucune page ne restitue la charge piégée sans échappement |
| `js-errors.py` | ouvre l'accueil et chaque page de `front/` dans Chromium (Playwright) : aucune erreur JavaScript ni boîte de dialogue |
| `purge.php` | supprime le jeu de données (entités et comptes « Securite ISO … », objets nommés « … securite ») d'une instance de test partagée ; `--dry-run` compte sans supprimer |
| `not-covered.txt` | pages de `front/` volontairement non testées, avec la raison |

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
  et au super-admin, comme la liste `READABLE`), `$SEE_B` et `$DENY`.
- `xss-check.sh` : `$P`, `id`, `xid`, `check <libellé> <url>`, `tab`.

Le contrôle commun de couverture (glpi-plugin-security v1.9.1 et suivantes) trouve le chemin d'une
page dans `access-matrix.d/` : une page testée dans un fragment n'a pas à figurer dans la liste
`READABLE` d'`access-matrix.sh` (appeler `readable front/<page>.php` dans le fragment).
