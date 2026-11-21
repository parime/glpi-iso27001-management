#!/usr/bin/env python3
"""Entrées de CHANGELOG en fragments : changes/unreleased/<issue>-<sujet>.md.

Une PR n'ajoute plus ses lignes sous « ## [Unreleased] » de CHANGELOG.md (que toutes les branches
modifient au même endroit) : elle crée un fichier, par exemple
changes/unreleased/123-supplier-certificates.md :

    ### Added

    - **Certificats des fournisseurs** (issue #123, mesure A.5.19). Texte de l'entrée...

Un fragment peut contenir plusieurs rubriques (### Added, ### Fixed...). À la publication,
release_bump.py appelle « build_changelog.py --release <version> <date> » : la section datée de la
version reçoit les lignes déjà écrites sous [Unreleased] (inchangées), puis les fragments, chacun à la
fin de sa rubrique (dans l'ordre des noms de fichier). Les fragments sont ensuite supprimés.

Usage :
    python3 tools/build_changelog.py --check      vérifie les fragments (CI), n'écrit rien
    python3 tools/build_changelog.py --stdout     affiche la section [Unreleased] telle qu'elle sera publiée
    python3 tools/build_changelog.py --release <version> <AAAA-MM-JJ>   publication (release_bump.py)
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FRAG = os.path.join(ROOT, 'changes', 'unreleased')
TARGET = os.path.join(ROOT, 'CHANGELOG.md')
# Rubriques de Keep a Changelog (et « Documentation », déjà utilisée), dans l'ordre d'ajout.
SECTIONS = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security', 'Documentation']
NAME = re.compile(r'^\d+-[a-z0-9]+(?:-[a-z0-9]+)*\.md$')
HEAD = '## [Unreleased]'
NL = '\n'


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read().replace('\r\n', NL)


def fragments(errors):
    """Fragments valides : ({rubrique: [blocs]}, [fichiers])."""
    files = sorted(f for f in os.listdir(FRAG) if f.endswith('.md') and f != 'README.md') \
        if os.path.isdir(FRAG) else []
    merged = {}
    for f in files:
        where = 'changes/unreleased/' + f
        text = read(os.path.join(FRAG, f))
        if not NAME.match(f):
            errors.append('%s : nom attendu <issue>-<mots-en-minuscules>.md' % where)
        if re.search(r'^#{1,2} ', text, flags=re.M):
            errors.append('%s : titres « # » ou « ## » interdits (la version est ajoutée à la publication)' % where)
        parts = re.split(r'^### (.*)$', text, flags=re.M)
        if parts[0].strip():
            errors.append('%s : texte avant la première rubrique « ### Added » (ou Fixed...)' % where)
        if len(parts) == 1:
            errors.append('%s : aucune rubrique « ### Added » (ou Fixed...)' % where)
        for k in range(1, len(parts), 2):
            name, body = parts[k].strip(), parts[k + 1].strip(NL)
            if name not in SECTIONS:
                errors.append('%s : rubrique « ### %s » inconnue (attendu : %s)' % (where, name, ', '.join(SECTIONS)))
            elif not body.strip():
                errors.append('%s : rubrique « ### %s » vide' % (where, name))
            else:
                merged.setdefault(name, []).append(body)
    return merged, files


def assemble(existing, extra):
    """Section [Unreleased] existante + fragments. L'existant n'est ni réordonné ni reformaté : chaque
    fragment va à la fin de sa rubrique si elle existe, sinon dans une nouvelle rubrique ajoutée en fin
    de section (dans l'ordre de SECTIONS)."""
    text = existing.strip(NL)
    for name in [n for n in SECTIONS if n in extra]:
        add = (NL + NL).join(extra[name])
        m = re.search(r'^### ' + re.escape(name) + r'[ \t]*$', text, flags=re.M)
        if m:
            nxt = re.search(r'^### ', text[m.end():], flags=re.M)
            cut = m.end() + nxt.start() if nxt else len(text)
            rest = text[cut:]
            text = text[:cut].rstrip(NL) + NL + NL + add + (NL + NL + rest if rest else '')
        else:
            text = (text + NL + NL if text else '') + '### ' + name + NL + NL + add
    return text


def main(argv):
    errors = []
    extra, files = fragments(errors)
    log = read(TARGET)
    if HEAD + NL not in log:
        errors.append('CHANGELOG.md : section « %s » introuvable' % HEAD)
    if errors:
        for e in errors:
            print('::error::CHANGELOG : ' + e)
        return 1
    start = log.index(HEAD + NL) + len(HEAD) + 1
    nxt = re.search(r'^## \[', log[start:], flags=re.M)
    end = start + nxt.start() if nxt else len(log)
    if '--check' in argv:
        print('CHANGELOG : %d fragment(s) valide(s) dans changes/unreleased/' % len(files))
        return 0
    body = assemble(log[start:end], extra)
    if '--stdout' in argv:
        sys.stdout.reconfigure(encoding='utf-8')
        sys.stdout.write(HEAD + NL + NL + body + NL)
        return 0
    if '--release' in argv:
        i = argv.index('--release')
        version, date = argv[i + 1], argv[i + 2]
        title = '## [' + version + '] - ' + date + NL
        if files:
            section = title + (NL + body + NL if body else '')
            log = log[:start] + NL + section + NL + log[end:].lstrip(NL)
        else:
            # Sans fragment : exactement l'ancien comportement (titre de version inséré, rien d'autre).
            log = log[:start] + NL + title + log[start:]
        with open(TARGET, 'w', encoding='utf-8', newline=NL) as fh:
            fh.write(log)
        for f in files:
            os.remove(os.path.join(FRAG, f))
        print('CHANGELOG : version %s, %d fragment(s) intégré(s)' % (version, len(files)))
        return 0
    print(__doc__)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
