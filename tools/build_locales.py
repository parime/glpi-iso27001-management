#!/usr/bin/env python3
"""Traductions en fragments et catalogues .mo compilés (issue #985).

Les fichiers locales/*.mo ne sont plus versionnés : ils sont compilés à partir des .po par la CI,
par l'outil de publication et au déploiement. Une PR n'ajoute plus ses textes dans les 20 fichiers
locales/*.po (que toutes les branches modifient) : elle crée, pour chaque langue de base, un fragment
locales/unreleased/<issue>-<sujet>.<langue>.po qui ne contient que ses entrées, par exemple
locales/unreleased/123-supplier-certificates.fr_FR.po :

    msgid ""
    msgstr ""
    "Content-Type: text/plain; charset=UTF-8\\n"

    msgid "Supplier certificates"
    msgstr "Certificats des fournisseurs"

Langues de base : celles de tools/sync-locale-variants.sh qui ont des variantes, plus les autres
langues des locales/*.po (de_DE, en_GB, es_ES, fr_FR, it_IT, pl_PL, pt_BR). Les variantes
régionales (fr_CA, es_MX...) n'ont pas de fragment : elles suivent leur langue de base. Une entrée
d'un fragment remplace celle du .po (correction d'une traduction existante).

Usage (GNU gettext requis : msgcat, msgfmt) :
    python3 tools/build_locales.py                  compile locales/*.mo (.po + fragments), sans
                                                    modifier les .po
    python3 tools/build_locales.py --check [--extracted <pot>]
                                                    vérifie les fragments (nom, langue, syntaxe,
                                                    mêmes msgid dans toutes les langues) ; avec
                                                    --extracted (.pot régénéré depuis le code) :
                                                    chaque chaîne du code est dans le .pot versionné
                                                    ou dans un fragment, et chaque entrée d'un
                                                    fragment existe dans le code
    python3 tools/build_locales.py --merge          range les fragments dans les .po (copie de
                                                    travail de la CI), sans les supprimer
    python3 tools/build_locales.py --release        publication (release_bump.py) : range les
                                                    fragments dans les .po, les supprime, régénère le
                                                    .pot (vendor/bin/extract-locales), resynchronise
                                                    les variantes et compile les .mo
"""
import glob
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOC = os.path.join(ROOT, 'locales')
FRAG = os.path.join(LOC, 'unreleased')
SYNC = os.path.join(ROOT, 'tools', 'sync-locale-variants.sh')
NAME = re.compile(r'^(\d+-[a-z0-9]+(?:-[a-z0-9]+)*)\.([a-z]{2,3}_[A-Z0-9]{2,3})\.po$')


def fail(msg):
    print('::error::' + msg)
    return 1


def run(*cmd, **kw):
    return subprocess.run(list(cmd), check=True, **kw)


def variants():
    """{langue de base: [variantes]} lu dans tools/sync-locale-variants.sh (source unique)."""
    out = {}
    if os.path.exists(SYNC):
        text = open(SYNC, encoding='utf-8').read()
        block = re.search(r'VARIANTS="([^"]*)"', text)
        for line in (block.group(1).splitlines() if block else []):
            if ':' in line:
                base, rest = line.split(':', 1)
                out[base.strip()] = rest.split()
    return out


def languages():
    """Langues de base : tous les locales/*.po sauf les variantes."""
    var = {v for vs in variants().values() for v in vs}
    return sorted(os.path.basename(p)[:-3] for p in glob.glob(os.path.join(LOC, '*.po'))
                  if os.path.basename(p)[:-3] not in var)


def fragments():
    """[(chemin, groupe <issue>-<sujet>, langue)] triés par nom de fichier."""
    out = []
    for path in sorted(glob.glob(os.path.join(FRAG, '*.po'))):
        m = NAME.match(os.path.basename(path))
        out.append((path, m.group(1) if m else None, m.group(2) if m else None))
    return out


def unquote(line):
    return line[line.index('"') + 1:line.rindex('"')]


def keys(path):
    """Clés (msgctxt, msgid) d'un .po ou .pot, chaînes concaténées telles qu'écrites (sans en-tête
    ni entrées obsolètes)."""
    found, entry, field = set(), {}, None

    def flush():
        if entry.get('msgid'):
            found.add((entry.get('msgctxt'), entry['msgid']))

    for raw in open(path, encoding='utf-8'):
        line = raw.strip()
        if line.startswith('#'):
            continue
        m = re.match(r'^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"', line)
        if m:
            field = m.group(1)
            # Nouvelle entrée : msgctxt, ou msgid alors que l'entrée en cours en a déjà un.
            if 'msgid' in entry and field in ('msgctxt', 'msgid'):
                flush()
                entry = {}
            entry[field] = unquote(line)
        elif line.startswith('"') and field:
            entry[field] += unquote(line)
        elif not line:
            field = None
    flush()
    return found


def header(po, dest):
    """Copie l'en-tête de po (commentaires de tête et entrée msgid "") dans dest."""
    lines, started = [], False
    for raw in open(po, encoding='utf-8'):
        if raw.startswith('msgid ""'):
            started = True
        if started and not raw.strip():
            break
        lines.append(raw)
    open(dest, 'w', encoding='utf-8', newline='\n').write(''.join(lines) + '\n')


def merged(po, frags, dest, tmp):
    """Écrit dans dest le .po po complété par les fragments : en-tête de po, entrées de po dans leur
    ordre (sauf celles qu'un fragment redéfinit), puis les entrées des fragments."""
    if not frags:
        shutil.copyfile(po, dest)
        return
    head, comb, uniq = (os.path.join(tmp, n) for n in ('head.po', 'frags.po', 'uniq.po'))
    header(po, head)
    run('msgcat', '--use-first', '-o', comb, *frags)
    # Entrées présentes dans un seul des deux fichiers (en-tête exclu : présent dans les deux).
    run('msgcat', '--unique', '--to-code=UTF-8', '-o', uniq, po, comb)
    run('msgcat', '--use-first', '-o', dest, head, *([uniq] if os.path.exists(uniq) else []), comb)


def by_language():
    out = {}
    for path, _group, lang in fragments():
        out.setdefault(lang, []).append(path)
    return out


def compile_mo(tmp):
    frags = by_language()
    for lang in languages():
        po = os.path.join(LOC, lang + '.po')
        full = os.path.join(tmp, lang + '.full.po')
        merged(po, frags.get(lang, []), full, tmp)
        mo = os.path.join(LOC, lang + '.mo')
        run('msgfmt', '--check-format', '-o', mo, full)
        for variant in variants().get(lang, []):
            if os.path.exists(os.path.join(LOC, variant + '.po')):
                shutil.copyfile(mo, os.path.join(LOC, variant + '.mo'))
    print('build_locales : %d catalogues .mo compilés' % len(glob.glob(os.path.join(LOC, '*.mo'))))


def merge_into_po(tmp):
    frags = by_language()
    for lang, paths in frags.items():
        po = os.path.join(LOC, lang + '.po')
        full = os.path.join(tmp, lang + '.full.po')
        merged(po, paths, full, tmp)
        shutil.copyfile(full, po)
    if frags and os.path.exists(SYNC):
        run('sh', SYNC)
    return frags


def check(extracted):
    status = 0
    langs = languages()
    groups = {}
    for path, group, lang in fragments():
        name = os.path.relpath(path, ROOT).replace(os.sep, '/')
        if not group:
            status |= fail(name + " : nom attendu <issue>-<sujet>.<langue>.po (exemple 123-supplier-certificates.fr_FR.po)")
            continue
        if lang not in langs:
            status |= fail(name + ' : langue inconnue ou variante régionale (langues de base : ' + ' '.join(langs) + ')')
            continue
        res = subprocess.run(['msgfmt', '--check-format', '--check-header', '-o', os.devnull, path],
                             capture_output=True, text=True)
        if res.returncode:
            status |= fail(name + ' : fichier PO invalide\n' + res.stderr.strip())
            continue
        groups.setdefault(group, {})[lang] = keys(path)
    for group, per_lang in sorted(groups.items()):
        missing = [lang for lang in langs if lang not in per_lang]
        if missing:
            status |= fail('locales/unreleased/%s : fragment manquant pour %s' % (group, ' '.join(missing)))
        ref = set().union(*per_lang.values())
        for lang, ks in sorted(per_lang.items()):
            for ctx, mid in sorted(ref - ks, key=str):
                status |= fail('locales/unreleased/%s.%s.po : traduction manquante pour "%s"' % (group, lang, mid))
    if extracted:
        code = keys(extracted)
        pots = glob.glob(os.path.join(LOC, '*.pot'))
        known = keys(pots[0]) if pots else set()
        frag_keys = set()
        for per_lang in groups.values():
            frag_keys |= set().union(*per_lang.values())
        for ctx, mid in sorted(code - known - frag_keys, key=str):
            status |= fail('chaîne du code sans traduction : "%s" : l\'ajouter dans un fragment '
                           'locales/unreleased/<issue>-<sujet>.<langue>.po' % mid)
        for ctx, mid in sorted(frag_keys - code, key=str):
            status |= fail('entrée de fragment absente du code (faute de frappe ?) : "%s"' % mid)
    if not status:
        print('build_locales : %d fragment(s) de traduction valides' % len(fragments()))
    return status


def release(tmp):
    frags = merge_into_po(tmp)
    for path, _group, _lang in fragments():
        os.remove(path)
    extract = os.path.join(ROOT, 'vendor', 'bin', 'extract-locales')
    if os.path.exists(extract) and shutil.which('xgettext'):
        en = os.path.join(LOC, 'en_GB.po')
        keep = open(en, 'rb').read()
        run('bash', extract, cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        open(en, 'wb').write(keep)  # extract-locales réécrit en_GB.po avec msginit
        for pot in glob.glob(os.path.join(LOC, '*.pot')):
            run('msgcat', '--sort-by-file', '-o', pot, pot)
        print('build_locales : .pot régénéré')
    else:
        print('build_locales : vendor/bin/extract-locales ou xgettext absent, .pot non régénéré')
    # extract-locales réécrit les langues de base après merge_into_po : les variantes régionales sont
    # recopiées ensuite, sinon elles gardent les traductions de la version précédente (es_MX, fr_CA…).
    if os.path.exists(SYNC):
        run('sh', SYNC)
    compile_mo(tmp)
    print('build_locales : %d langue(s) complétée(s) par des fragments' % len(frags))


def main(argv):
    if not shutil.which('msgcat') or not shutil.which('msgfmt'):
        return fail('GNU gettext (msgcat, msgfmt) introuvable')
    extracted = None
    if '--extracted' in argv:
        extracted = argv[argv.index('--extracted') + 1]
    with tempfile.TemporaryDirectory() as tmp:
        if '--check' in argv:
            return check(extracted)
        if '--merge' in argv:
            merge_into_po(tmp)
            return 0
        if '--release' in argv:
            if check(None):
                return 1
            release(tmp)
            return 0
        compile_mo(tmp)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
