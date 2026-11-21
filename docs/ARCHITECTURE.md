[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)

## 🇫🇷 Français

### Ajouter une fonctionnalité sans conflit

Plusieurs fonctionnalités sont développées en même temps, sur des branches tirées de `dev`. Quand
chacune ajoute des lignes à la même liste centrale, presque chaque PR entre en conflit avec les
autres. La règle est donc : **une nouvelle fonctionnalité s'ajoute en créant de nouveaux fichiers**,
découverts et assemblés automatiquement, sans modifier les listes centrales.

| Pour ajouter... | Créer | Ne plus modifier |
|---|---|---|
| une étape du tutoriel | `docs/tutorial/fr/NNNN-mots.md` et `docs/tutorial/en/NNNN-mots.md` | `docs/TUTORIAL.md` |

#### Tutoriel

- Une étape = un fichier par langue, **du même nom** dans `docs/tutorial/fr/` et
  `docs/tutorial/en/`, par exemple `0420-follow-supplier-certificates.md`.
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

## 🇬🇧 English

### Adding a feature without conflicts

Several features are developed at the same time, on branches taken from `dev`. When each one adds
lines to the same central list, nearly every pull request conflicts with the others. Hence the
rule: **a new feature is added by creating new files**, discovered and assembled automatically,
without changing the central lists.

| To add... | Create | No longer edit |
|---|---|---|
| a tutorial step | `docs/tutorial/fr/NNNN-words.md` and `docs/tutorial/en/NNNN-words.md` | `docs/TUTORIAL.md` |

#### Tutorial

- One step = one file per language, **with the same name** in `docs/tutorial/fr/` and
  `docs/tutorial/en/`, for example `0420-follow-supplier-certificates.md`.
- The name starts with four digits that set the order: take a number greater than the last
  fragment (numbers go by tens, which leaves room to insert a step between two others). Two
  branches that take the same number do not clash: the rest of the names differ, and the order
  stays stable.
- The file starts with `### <title of the step>`, **without a number**: "Étape 12 :" and
  "Step 12:" are added when assembling. To refer to another step, write
  `{{step:0030-configure-probability-x-impact-matrix}}` (file name without `.md`), replaced by its
  number.
- Images stay relative to `docs/`: `![...](screenshots/xxx.png)`.
- `docs/TUTORIAL.md` is **generated** by `python3 tools/build_tutorial.py` when the version is
  released (release tool). A pull request does not change it; CI rejects a change that does not
  match the fragments. To review the result: `python3 tools/build_tutorial.py --stdout`. CI also
  checks that no language is missing and that every `{{step:...}}` exists (`--check`).
