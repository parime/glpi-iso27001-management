#!/usr/bin/env python3
"""Assemble docs/TUTORIAL.md à partir des fragments docs/tutorial/{fr,en}/*.md.

Chaque étape du tutoriel est un fichier par langue, du même nom dans fr/ et en/ :
    docs/tutorial/fr/0420-suivre-quelque-chose.md
    docs/tutorial/en/0420-suivre-quelque-chose.md
Le fragment commence par « ### <titre de l'étape> », sans numéro : les étapes sont numérotées ici,
dans l'ordre alphabétique des noms de fichier (d'où le préfixe à quatre chiffres). Pour citer une
autre étape : {{step:<nom du fichier sans .md>}}, remplacé par son numéro. _intro.md est le texte
d'introduction de la langue. Les chemins des images sont relatifs à docs/ (comme dans TUTORIAL.md).

Usage :
    python3 tools/build_tutorial.py            réécrit docs/TUTORIAL.md
    python3 tools/build_tutorial.py --check    vérifie les fragments (CI), n'écrit rien
    python3 tools/build_tutorial.py --stdout   affiche le résultat sans l'écrire
    python3 tools/build_tutorial.py --check-file   vérifie aussi que docs/TUTORIAL.md est à jour
    python3 tools/build_tutorial.py --import   migration : chaque étape écrite à la main dans
                                               docs/TUTORIAL.md devient un fragment, puis réassemble

docs/TUTORIAL.md n'est réécrit qu'à la publication (release_bump.py) : une PR ajoute un fragment et
ne touche pas à TUTORIAL.md, ce qui supprime les conflits et les numéros d'étape écrits à la main.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FRAG = os.path.join(ROOT, 'docs', 'tutorial')
TARGET = os.path.join(ROOT, 'docs', 'TUTORIAL.md')
LANGS = [
    # dossier, titre de section, format du titre d'étape
    ('fr', '## 🇫🇷 Français', '### Étape {n} : {title}'),
    ('en', '## 🇬🇧 English', '### Step {n}: {title}'),
]
NAME = re.compile(r'^\d{4}-[a-z0-9]+(?:-[a-z0-9]+)*\.md$')
REF = re.compile(r'\{\{step:([^}]*)\}\}')
NUMBERED = re.compile(r'^(?:Étape|Step)\s*\d', re.IGNORECASE)
HEADER = ('[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)\n\n'
          '<!-- Fichier généré par tools/build_tutorial.py à partir de docs/tutorial/ (à la publication) :\n'
          '     ne pas le modifier à la main, ajouter ou modifier un fragment. -->\n')


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read().replace('\r\n', '\n')


def load():
    errors = []
    names = {}
    for lang, _, _ in LANGS:
        folder = os.path.join(FRAG, lang)
        files = sorted(os.listdir(folder)) if os.path.isdir(folder) else []
        names[lang] = [f for f in files if f != '_intro.md']
        for f in names[lang]:
            if not NAME.match(f):
                errors.append('%s/%s : nom attendu NNNN-mots-en-minuscules.md' % (lang, f))
        if '_intro.md' not in files:
            errors.append('%s/_intro.md manquant' % lang)
    ref = names[LANGS[0][0]]
    for lang, _, _ in LANGS[1:]:
        for f in sorted(set(ref) ^ set(names[lang])):
            missing = lang if f in ref else LANGS[0][0]
            errors.append('%s/%s manquant (chaque étape existe en %s)'
                          % (missing, f, ' et '.join(lg for lg, _, _ in LANGS)))
    steps = [f for f in ref if all(f in names[lg] for lg, _, _ in LANGS)]
    number = {f[:-3]: i + 1 for i, f in enumerate(steps)}
    return steps, number, errors


def render(steps, number, errors):
    out = [HEADER]
    for lang, section, fmt in LANGS:
        out.append(section + '\n\n' + read(os.path.join(FRAG, lang, '_intro.md')).strip('\n') + '\n')
        for f in steps:
            text = read(os.path.join(FRAG, lang, f)).strip('\n')
            first, _, body = text.partition('\n')
            if not first.startswith('### ') or first.startswith('####'):
                errors.append('%s/%s : la première ligne doit être « ### <titre> »' % (lang, f))
                continue
            title = first[4:].strip()
            if NUMBERED.match(title):
                errors.append('%s/%s : pas de numéro d\'étape dans le titre (il est ajouté seul)' % (lang, f))

            def ref(m, lang=lang, f=f):
                key = m.group(1).strip()
                if key not in number:
                    errors.append('%s/%s : {{step:%s}} ne correspond à aucun fragment' % (lang, f, key))
                    return m.group(0)
                return str(number[key])
            body = REF.sub(ref, body.strip('\n'))
            out.append(fmt.format(n=number[f[:-3]], title=title) + '\n\n' + body + '\n')
    return '\n'.join(out)


def parse_target():
    """Étapes de docs/TUTORIAL.md par langue : liste de (titre, corps)."""
    text = read(TARGET)
    steps = {}
    for i, (lang, section, fmt) in enumerate(LANGS):
        start = text.index(section + '\n')
        end = text.index(LANGS[i + 1][1] + '\n') if i + 1 < len(LANGS) else len(text)
        head = re.escape(fmt.split('{n}')[0]) + r'\d+(?:\s*bis|[a-z])?' + re.escape(fmt.split('{n}')[1].split('{title}')[0])
        parts = re.split(r'^' + head + r'(.*)$', text[start:end], flags=re.M)
        steps[lang] = [(parts[k].strip(), parts[k + 1].strip('\n')) for k in range(1, len(parts) - 1, 2)
                       if parts[k + 1].strip()]
    return steps


def import_manual_steps():
    """Migration : crée un fragment pour chaque étape écrite à la main dans docs/TUTORIAL.md."""
    known = {lang: set() for lang, _, _ in LANGS}
    for lang, _, _ in LANGS:
        folder = os.path.join(FRAG, lang)
        for f in os.listdir(folder):
            if f != '_intro.md':
                known[lang].add(read(os.path.join(folder, f)).split('\n', 1)[0][4:].strip())
    found = parse_target()
    new = {lang: [s for s in found[lang] if s[0] not in known[lang]] for lang, _, _ in LANGS}
    counts = {len(v) for v in new.values()}
    if len(counts) != 1:
        print('::error::étapes ajoutées à la main : %s ; nombre différent selon la langue, créer les fragments '
              'à la main' % ', '.join('%s %d' % (k, len(v)) for k, v in new.items()))
        return 1
    existing = [f for f in os.listdir(os.path.join(FRAG, LANGS[0][0])) if NAME.match(f)]
    last = max(int(f[:4]) for f in existing) if existing else 0
    en = [lg for lg, _, _ in LANGS if lg == 'en'] or [LANGS[-1][0]]
    for k in range(counts.pop()):
        last += 10
        words = [w for w in re.findall(r'[a-z0-9]+', re.sub(r'\(.*?\)', '', new[en[0]][k][0].lower()))
                 if len(w) > 2][:5]
        name = '%04d-%s.md' % (last, '-'.join(words) or 'step')
        for lang, _, _ in LANGS:
            title, body = new[lang][k]
            with open(os.path.join(FRAG, lang, name), 'w', encoding='utf-8', newline='\n') as fh:
                fh.write('### ' + title + '\n\n' + body.strip('\n') + '\n')
        print('tutoriel : fragment créé %s (%s)' % (name, new[en[0]][k][0]))
    return 0


def main(argv):
    if '--import' in argv:
        status = import_manual_steps()
        if status:
            return status
    steps, number, errors = load()
    result = render(steps, number, errors)
    if errors:
        for e in errors:
            print('::error::tutoriel : ' + e)
        return 1
    if '--check-file' in argv:
        current = read(TARGET) if os.path.exists(TARGET) else ''
        if current != result:
            print('::error::docs/TUTORIAL.md ne correspond pas aux fragments de docs/tutorial/. Ne modifiez pas '
                  'TUTORIAL.md à la main : ajoutez un fragment (docs/ARCHITECTURE.md, « Ajouter une '
                  'fonctionnalité sans conflit »). Étape déjà écrite dans TUTORIAL.md : '
                  'python3 tools/build_tutorial.py --import la transforme en fragment.')
            return 1
        print('tutoriel : docs/TUTORIAL.md à jour (%d étapes)' % len(steps))
        return 0
    if '--check' in argv:
        print('tutoriel : %d étapes, fragments valides' % len(steps))
        return 0
    if '--stdout' in argv:
        sys.stdout.reconfigure(encoding='utf-8')
        sys.stdout.write(result)
        return 0
    with open(TARGET, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(result)
    print('tutoriel : docs/TUTORIAL.md régénéré (%d étapes)' % len(steps))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
