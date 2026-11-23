"""Anciennes adresses du plugin dans un vrai navigateur (issue #1050).

Le parcours commun de toutes les pages (glpi-plugin-security, checks/crawl-pages.py, lancé par
.github/workflows/security.yml) vérifie les codes HTTP, les pages blanches, les erreurs PHP et
JavaScript, le fil d'Ariane et les pages qu'aucun menu n'atteint. Il ne connaît pas les anciennes
adresses du plugin ; ce script les vérifie, pour chaque compte :
- chaque ancienne adresse de old-urls.txt mène, après redirection, à sa nouvelle adresse ;
- chaque ancienne ancre de la configuration (carte data-anchors de #grc-config-tabs, tenue à jour
  par les blocs de réglages : rien à ajouter ici quand un bloc change d'onglet) mène à son onglet
  ou à l'écran d'exploitation qui accueille l'ancien bloc ;
- la page d'arrivée répond 200, sans erreur PHP, avec un fil d'Ariane présent et non doublé.
Un compte autre que le premier peut recevoir un refus propre (403) : il n'a pas forcément le droit.

Usage : python3 old-urls.py <url GLPI> [identifiant:mot-de-passe]...
Sans compte : glpi:glpi. Sortie : une ligne par anomalie, puis « RESULT old-urls ... » ; code 1 s'il
reste une anomalie.
"""
import json
import os
import re
import sys
from urllib.parse import parse_qsl, urljoin, urlsplit

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip('/')
ACCOUNTS = [a.split(':', 1) for a in sys.argv[2:]] or [['glpi', 'glpi']]
HERE = os.path.dirname(os.path.abspath(__file__))
CONFIG = 'plugins/grcmanager/front/config.php'
ERR = re.compile(r'Fatal error|Uncaught |Stack trace|Twig\\Error'
                 r'|Undefined (variable|array key|method)|Call to undefined|SQL error', re.I)
CRUMBS = 'nav ol.breadcrumb li.breadcrumb-item, #grc-config-breadcrumb li'


def load_old_urls():
    """Lignes « ancienne -> nouvelle » de old-urls.txt (adresses depuis la racine de GLPI)."""
    pairs = []
    with open(os.path.join(HERE, 'old-urls.txt'), encoding='utf-8') as fh:
        for line in fh:
            line = line.strip()
            if line and not line.startswith('#'):
                old, new = (part.strip() for part in line.split('->', 1))
                pairs.append((old, new))
    return pairs


def lands_on(final, target):
    """Vrai si l'adresse finale a le chemin et tous les paramètres de la cible."""
    f, t = urlsplit(final), urlsplit(target)
    return f.path.endswith(t.path) and set(parse_qsl(t.query)) <= set(parse_qsl(f.query))


def login(ctx, user, password):
    pg = ctx.new_page()
    pg.set_default_timeout(60000)
    pg.goto(BASE + '/index.php')
    pg.fill('input[name="login_name"]', user)
    pg.fill('input[type="password"]', password)
    pg.click('button[type="submit"]')
    pg.wait_for_load_state('load')
    return pg if 'login_name' not in pg.content() else None


def visit(pg, url, target, first, bad, label):
    """Ouvre l'ancienne adresse et vérifie la page d'arrivée."""
    try:
        pg.goto('about:blank')  # sinon une ancre seule ne recharge pas la page
        resp = pg.goto(url, wait_until='load')
        if '#' in url:  # redirection de l'ancre par le script de la page
            pg.wait_for_timeout(1000)
            pg.wait_for_load_state('load')
        try:
            pg.wait_for_load_state('networkidle', timeout=5000)
        except Exception:
            pass
    except Exception as exc:
        bad.append('%s : navigation : %s' % (label, str(exc).splitlines()[0][:120]))
        return False
    status = resp.status if resp else 0
    if status == 403 and not first:
        return True
    if status != 200 or ERR.search(pg.content()):
        bad.append('%s : HTTP %d ou erreur PHP' % (label, status))
    elif not lands_on(pg.url, target):
        bad.append('%s : attendu %s, obtenu %s' % (label, target, pg.url))
    else:
        crumbs = [c.strip() for c in pg.locator(CRUMBS).all_inner_texts() if c.strip()]
        if not crumbs:
            bad.append("%s : fil d'Ariane absent" % label)
        elif ' '.join(crumbs).count('Plugins') > 1:
            bad.append("%s : fil d'Ariane doublé : %s" % (label, ' > '.join(crumbs)[:100]))
        else:
            return True
    return False


def main():
    bad, checked = [], 0
    old_urls = load_old_urls()
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for index, (user, password) in enumerate(ACCOUNTS):
            ctx = browser.new_context(viewport={'width': 1440, 'height': 1000})
            pg = login(ctx, user, password)
            if pg is None:
                bad.append('%s : connexion impossible' % user)
                ctx.close()
                continue
            first = index == 0
            checks = [(BASE + '/' + old, urljoin(BASE + '/', new), old) for old, new in old_urls]
            pg.goto(BASE + '/' + CONFIG, wait_until='load')
            bar = pg.locator('#grc-config-tabs')
            if bar.count():
                anchors = json.loads(bar.get_attribute('data-anchors') or '{}')
                for anchor, target in sorted(anchors.items()):
                    checks.append((BASE + '/' + CONFIG + '#' + anchor, urljoin(pg.url, target),
                                   'config.php#' + anchor))
            elif first:
                bad.append("%s : config.php sans barre d'onglets #grc-config-tabs" % user)
            for url, target, label in checks:
                checked += visit(pg, url, target, first, bad, user + ' ' + label)
            ctx.close()
        browser.close()
    for line in bad:
        print('::error::ancienne adresse : ' + line)
    print('RESULT old-urls ok=%d anomalies=%d' % (checked, len(bad)))
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
