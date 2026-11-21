"""Erreurs JavaScript de bout en bout (issue #978).

Ouvre dans un vrai navigateur (Chromium, Playwright) la page d'accueil de GLPI et chaque page de
front/ du plugin, en super-admin, et relève :
- les erreurs JavaScript non interceptées (événement « pageerror », par exemple
  « Maximum call stack size exceeded ») ;
- les boîtes de dialogue ouvertes par la page (« alert ») : après `setup.php xss`, une charge
  piégée exécutée au lieu d'être échappée en ouvre une.

Une erreur n'est retenue que si elle revient à une seconde visite de la page : une erreur isolée,
due à une page quittée en plein chargement sur une machine lente, est signalée en avertissement.

Usage : python3 js-errors.py <url GLPI> <dossier front/ du plugin> [identifiant] [mot de passe]
"""
import glob
import os
import sys

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip('/')
FRONT = sys.argv[2]
LOGIN = sys.argv[3] if len(sys.argv) > 3 else 'glpi'
PASSWORD = sys.argv[4] if len(sys.argv) > 4 else 'glpi'

PAGES = ['front/central.php'] + [
    'plugins/grcmanager/front/' + os.path.basename(f) for f in sorted(glob.glob(os.path.join(FRONT, '*.php')))
]


def visit(page, url, found):
    """Ouvre la page et laisse ses scripts et ses appels AJAX se terminer (2 s au plus)."""
    found.clear()
    try:
        page.goto(BASE + '/' + url, wait_until='load')
    except Exception as exc:  # téléchargement direct (PDF, CSV) : aucune page à vérifier
        if 'Download is starting' not in str(exc):
            found.append('navigation : ' + str(exc).splitlines()[0][:200])
        return list(found)
    try:
        page.wait_for_load_state('networkidle', timeout=2000)
    except Exception:
        pass
    return list(found)


def main():
    failures, warnings = [], []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport={'width': 1440, 'height': 1000})
        page = context.new_page()
        page.set_default_timeout(60000)
        page.goto(BASE + '/index.php')
        page.fill('input[name="login_name"]', LOGIN)
        page.fill('input[type="password"]', PASSWORD)
        page.click('button[type="submit"]')
        page.wait_for_load_state('load')
        if 'login_name' in page.content():
            print('::error::connexion impossible')
            return 1

        found = []
        page.on('pageerror', lambda e: found.append('erreur JS : ' + str(e)[:300]))

        def on_dialog(dialog):
            found.append('boîte de dialogue « %s » : %s' % (dialog.type, dialog.message[:200]))
            dialog.dismiss()

        page.on('dialog', on_dialog)

        for url in PAGES:
            first = visit(page, url, found)
            if not first:
                continue
            again = visit(page, url, found)
            if again:
                failures += ['%s : %s' % (url, msg) for msg in sorted(set(first + again))]
            else:
                warnings += ['%s : %s (une seule fois sur deux visites)' % (url, msg) for msg in sorted(set(first))]
            print('%s : %d erreur(s) puis %d a la seconde visite' % (url, len(first), len(again)), flush=True)
        browser.close()

    for msg in warnings:
        print('::warning::' + msg)
    for msg in failures:
        print('::error::' + msg)
    print('%d pages ouvertes, %d erreur(s), %d avertissement(s)' % (len(PAGES), len(failures), len(warnings)))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
