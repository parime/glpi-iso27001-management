# Tests de sécurité de bout en bout

Lancés par la CI (job d'installation réelle) dans une instance GLPI jetable :

| Script | Rôle |
|---|---|
| `setup.php` | crée le jeu de données (deux entités A et B, profils limités) et affiche les identifiants en JSON ; `setup.php xss '<json>'` crée les données piégées |
| `access-matrix.sh` | matrice de contrôle d'accès, une fois par profil (`none`, `reader`, `limited`, `general`, `admin`) |
| `xss-check.sh` | vérifie qu'aucune page ne restitue la charge piégée sans échappement |
| `js-errors.py` | ouvre l'accueil et chaque page de `front/` dans Chromium (Playwright) : aucune erreur JavaScript ni boîte de dialogue |
| `crawl.py` | parcours de toutes les pages (menus, sous-pages, `front/`, fiches et onglets), en super-admin et en lecteur : codes HTTP, page blanche, erreurs PHP et JavaScript, fil d'Ariane, anciennes adresses (voir plus bas) |
| `crawl-ids.php` | données du parcours lues dans GLPI : une fiche existante de chaque formulaire, fil d'Ariane attendu de chaque entrée de menu |
| `crawl-redirects.txt` | anciennes adresses des pages déplacées et leur nouvelle adresse |
| `crawl-known.txt` | anomalies du parcours déjà connues, à corriger (fil d'Ariane incomplet...) |
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

## Parcours de toutes les pages (`crawl.py`)

Lancé par la CI sur chaque PR, en GLPI 11 et 12 (étape « Parcours de toutes les pages »), et avant
chaque publication. Pour chaque compte, il ouvre dans Chromium l'accueil de GLPI et chaque page du
plugin, découvertes sans liste à tenir : entrées du menu de GLPI, barres d'onglets des rubriques,
onglets de la configuration, liens du fil d'Ariane, chaque fichier de `front/`, une fiche existante
de chaque formulaire et les écrans de GLPI modifiés par le plugin, avec les onglets du plugin.
Une nouvelle page est donc parcourue sans modifier le script.

Anomalies (échec) :

- 500, erreur PHP ou Twig affichée, page blanche ;
- page proposée par un lien (menu, sous-page, fil d'Ariane) en 404, ou en 403 pour le
  super-administrateur (premier compte) ; un 403 est normal pour le lecteur, et un fichier de
  `front/` ouvert sans paramètre peut être une action (POST, jeton, identifiant obligatoire) où 400,
  403 et 404 sont normaux ;
- erreur JavaScript ou boîte de dialogue qui revient à une seconde visite ;
- fil d'Ariane absent ou incomplet (accueil, secteur, rubrique) dans la mise en page de GLPI et,
  pour le super-administrateur, différent de l'entrée de menu qui mène à la page (secteur,
  rubrique et sous-page, tirés du menu que construisent les déclarations de rubriques) ;
- onglet du plugin en erreur ;
- ancienne adresse de `crawl-redirects.txt` qui ne mène plus à sa nouvelle adresse.

Les pages de `front/` qu'aucun menu ni aucune barre d'onglets ne propose sont listées « hors menu »,
pour information (pages d'action, exports, formulaires ouverts depuis une liste).

Lancement local, sur une instance où le plugin est déployé (Python 3 et Playwright :
`pip install playwright && python -m playwright install chromium`) :

```sh
# Le script lance lui-même setup.php et crawl-ids.php dans le conteneur GLPI :
python3 tests/security/crawl.py http://localhost:8080 --docker <conteneur GLPI>
# Sans accès à Docker : données de crawl-ids.php passées à la main
docker exec -u www-data <conteneur> php /var/www/glpi/plugins/grcmanager/tests/security/crawl-ids.php > ids.json
python3 tests/security/crawl.py http://localhost:8080 --ids @ids.json   --account glpi:glpi --account 'iso-lecteur:Secrev#2026' --log crawl-js.log
```

Comptes par défaut : `glpi:glpi` (super-administrateur, toujours en premier) et `iso-lecteur`
(jeu de données `setup.php`). `--log` écrit le message et la pile de chaque erreur JavaScript.
La sortie se termine par 7 lignes, lues par les scripts de publication : pour chaque compte, ses
compteurs, `ANOMALIES [...]` et `JS [...]`, puis `pages <n>`. Code de sortie 1 s'il y a une
anomalie.

Page déplacée : ajouter une ligne `ancienne -> nouvelle` dans `crawl-redirects.txt`.

Anomalies déjà connues : `crawl-known.txt` (`<page> <type>` par ligne, type `ARIANE`, `403`,
`404`...). Elles sont listées (« connue ... ») sans faire échouer le parcours ; une ligne qui ne se
produit plus est signalée et doit être retirée. Ne pas y ajouter une anomalie nouvelle : la corriger.
