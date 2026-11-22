"""Parcours de toutes les pages du plugin dans un vrai navigateur (issue #1050).

Ouvre dans Chromium (Playwright), pour chaque compte, l'accueil de GLPI puis chaque page du plugin, et
signale comme anomalie :
- une page en 404 ou en 500, une erreur PHP ou Twig affichée, une page blanche ;
- un refus (403) pour le super-administrateur (premier compte) ;
- une erreur JavaScript ou une boîte de dialogue qui revient à une seconde visite (une erreur isolée,
  due à une page quittée en plein chargement sur une machine lente, est seulement listée) ;
- un fil d'Ariane absent ou incomplet (accueil, secteur, rubrique) sur une page affichée dans la mise
  en page de GLPI, ou, pour le premier compte, différent de l'entrée de menu qui mène à la page ;
- un onglet du plugin (sur ses fiches et sur les écrans de GLPI qu'il modifie) en erreur ;
- une ancienne adresse (crawl-redirects.txt) qui ne mène plus à sa nouvelle adresse.

Pages parcourues, découvertes sans liste à tenir : les entrées du menu de GLPI, les sous-pages (barres
d'onglets des rubriques, onglets de la configuration, liens du fil d'Ariane), chaque fichier de
front/, une fiche existante de chaque formulaire et les écrans de GLPI modifiés par le plugin, avec
leurs onglets. Une page de front/ qu'aucun menu ni aucune barre d'onglets ne propose est listée
(« hors menu ») sans être une anomalie : page d'action, export, formulaire ouvert depuis une liste.

Données lues dans GLPI par crawl-ids.php (fiches existantes et fil d'Ariane attendu de chaque entrée
de menu) : passées par --ids, ou obtenues par --docker <conteneur>, qui lance aussi setup.php.

Usage :
  python3 crawl.py <url GLPI> [--account identifiant:mot-de-passe]... [--ids <json>|@<fichier>]
                   [--docker <conteneur>] [--front <dossier front/>] [--log <fichier>]
Par défaut : comptes glpi:glpi (super-administrateur) et iso-lecteur (jeu de données setup.php),
dossier front/ du dépôt. Sortie : les détails, puis 7 lignes (3 par compte : compteurs,
« ANOMALIES [...] », « JS [...] », puis « pages <n> ») ; code de sortie 1 s'il y a une anomalie.
"""
import argparse
import glob
import json
import os
import re
import subprocess
import sys
from urllib.parse import urljoin, urlsplit

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = 'plugins/grcmanager/front/'
ERR = re.compile(r'Fatal error|Uncaught|Stack trace|Twig\\Error|Undefined (variable|array key|method)'
                 r'|Call to undefined', re.I)
# Onglets du plugin : ceux de ses fiches et ceux qu'il ajoute aux écrans de GLPI.
TAB = '[data-bs-toggle="tab"][data-glpi-ajax-content*="rcmanager"], [data-bs-toggle="tab"][href*="rcmanager"]'
# Sous-pages : barre d'onglets d'une rubrique (_section_nav.html.twig), onglets de la configuration,
# liens du fil d'Ariane.
SUBPAGES = ('ul.nav-tabs a.nav-link[href]:not([data-bs-toggle]), #grc-config-tabs a[href]:not([data-bs-toggle]), '
            'nav ol.breadcrumb a[href]')
CRUMBS = 'nav ol.breadcrumb li.breadcrumb-item'
# Une page dans la mise en page de GLPI (menu latéral ou barre du haut) doit avoir un fil d'Ariane.
LAYOUT = 'aside.navbar, header.navbar'
MAX_PAGES = 1500


def parse_args():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument('base')
    ap.add_argument('--account', action='append', default=[])
    ap.add_argument('--ids', default='')
    ap.add_argument('--docker', default='')
    ap.add_argument('--front', default=os.path.join(HERE, '..', '..', 'front'))
    ap.add_argument('--redirects', default=os.path.join(HERE, 'crawl-redirects.txt'))
    ap.add_argument('--log', default='')
    args = ap.parse_args()
    args.base = args.base.rstrip('/')
    if not args.account:
        args.account = ['glpi:glpi', 'iso-lecteur:Secrev#2026']
    return args


def docker_data(container):
    """Jeu de données des tests de sécurité, puis crawl-ids.php, dans le conteneur GLPI."""
    env = dict(os.environ, MSYS_NO_PATHCONV='1')
    php = ['docker', 'exec', '-u', 'www-data', '-w', '/var/www/glpi', container, 'php']
    run = dict(capture_output=True, text=True, encoding='utf-8', errors='replace', env=env, timeout=900)
    setup = subprocess.run(php + ['plugins/grcmanager/tests/security/setup.php'], **run)
    if setup.returncode:
        print('setup.php : code %d %s' % (setup.returncode, (setup.stderr or '')[-500:]))
    found = subprocess.run(php + ['plugins/grcmanager/tests/security/crawl-ids.php'], **run)
    if found.returncode:
        print('crawl-ids.php : code %d %s' % (found.returncode, (found.stderr or '')[-500:]))
    return found.stdout or ''


def load_ids(args):
    raw = docker_data(args.docker) if args.docker else args.ids
    if raw.startswith('@'):
        with open(raw[1:], encoding='utf-8') as f:
            raw = f.read()
    lines = raw.strip().splitlines()
    try:
        data = json.loads(lines[-1]) if lines else {}
    except ValueError:
        print('données de crawl-ids.php illisibles : ' + raw[-300:])
        data = {}
    return data.get('forms', {}), data.get('menu', {})


def load_redirects(path):
    """Anciennes adresses : « ancienne -> attendue » par ligne, depuis la racine de GLPI."""
    out = []
    if os.path.exists(path):
        with open(path, encoding='utf-8') as f:
            for line in f:
                line = line.split('#', 1)[0].strip() if not line.lstrip().startswith('#') else ''
                if '->' in line:
                    old, new = (part.strip() for part in line.split('->', 1))
                    out.append((old, new))
    return out


def norm(text):
    return re.sub(r'\s+', ' ', text or '').strip()


class Crawler:
    def __init__(self, args, forms, menu):
        self.base = args.base
        self.front_pages = sorted(os.path.basename(f) for f in glob.glob(os.path.join(args.front, '*.php')))
        # Fiches existantes du plugin, puis écrans de GLPI modifiés par le plugin.
        self.forms = ['%s%s?id=%d' % (PLUGIN, os.path.basename(p), n) for p, n in sorted(forms.items())
                      if p.startswith(PLUGIN) and os.path.basename(p) in self.front_pages]
        self.core = ['%s?id=%d' % (p, n) for p, n in sorted(forms.items()) if p.startswith('front/')]
        self.core += ['front/ticket.form.php', 'front/change.form.php']
        self.menu = {PLUGIN + k: v for k, v in menu.items()}
        self.redirects = load_redirects(args.redirects)
        self.log = []

    def rel(self, url):
        """Adresse depuis la racine de GLPI (chemin et requête), ou None hors de l'instance."""
        absolute = urljoin(self.base + '/', url)
        if not absolute.startswith(self.base + '/'):
            return None
        parts = urlsplit(absolute[len(self.base) + 1:])
        return parts.path + ('?' + parts.query if parts.query else '')

    def login(self, ctx, login, password):
        pg = ctx.new_page()
        pg.set_default_timeout(60000)
        pg.goto(self.base + '/index.php')
        pg.fill('input[name="login_name"]', login)
        pg.fill('input[type="password"]', password)
        pg.click('button[type="submit"]')
        pg.wait_for_load_state('load')
        return pg, 'login_name' not in pg.content()

    def links(self, pg, selector):
        try:
            hrefs = pg.eval_on_selector_all(selector, 'els => els.map(e => e.getAttribute("href") || "")')
        except Exception:
            return []
        out = []
        for href in hrefs:
            if href and not href.startswith(('#', 'javascript:')):
                rel = self.rel(urljoin(pg.url, href))
                if rel and PLUGIN in rel:
                    out.append(rel)
        return out

    def menu_links(self, pg):
        """Entrées du menu de GLPI qui mènent au plugin (menu latéral ou barre du haut)."""
        return self.links(pg, 'aside.navbar a[href], header.navbar a[href], #navbar-menu a[href]')

    def check_crumbs(self, pg, name, admin, bad):
        try:
            if not pg.locator(LAYOUT).count():
                return 'sans mise en page'
            items = [norm(t) for t in pg.locator(CRUMBS).all_inner_texts()]
        except Exception:
            return 'illisible'
        if len(items) < 3:
            bad.append((name, 'ARIANE', 'fil incomplet : %s' % ' > '.join(items)))
            return 'incomplet'
        expected = self.menu.get(name) if admin else None
        if expected:
            got = items[1:1 + len(expected)]
            ok = len(got) == len(expected) and all(
                g == e or (g.endswith('...') and e.startswith(g[:-3])) for g, e in zip(got, expected))
            if not ok:
                bad.append((name, 'ARIANE', 'attendu %s, obtenu %s' % (' > '.join(expected), ' > '.join(items[1:]))))
                return 'different'
        return 'ok'

    def check_tabs(self, pg, admin, seen, bad, counts):
        """Onglets du plugin de la page : contenu demandé comme le fait GLPI, sans erreur."""
        try:
            urls = pg.eval_on_selector_all(TAB, 'els => els.map(e => e.getAttribute("data-glpi-ajax-content") || "")')
        except Exception:
            return
        for url in urls:
            if not url or url in seen:
                continue
            seen.add(url)
            try:
                r = pg.request.get(urljoin(pg.url, url), headers={'X-Requested-With': 'XMLHttpRequest'},
                                   timeout=60000)
                st, body = r.status, r.text()
            except Exception as exc:
                bad.append((url, 'ONGLET', str(exc).splitlines()[0][:120]))
                continue
            counts['onglets'] = counts.get('onglets', 0) + 1
            m = ERR.search(body)
            if st >= 500 or st == 404 or (admin and st == 403) or m:
                bad.append((url, st, 'onglet' + (' : ' + m.group(0) if m else '')))

    def open(self, pg, name, click_tabs):
        r = pg.goto(self.base + '/' + name, wait_until='load')
        try:
            pg.wait_for_load_state('networkidle', timeout=3000)
        except Exception:
            pass
        if click_tabs:  # écran de GLPI : chaque onglet du plugin, ouvert pour ses scripts
            tabs = pg.locator(TAB)
            for i in range(min(tabs.count(), 30)):
                try:
                    tabs.nth(i).click(timeout=5000)
                    pg.wait_for_load_state('networkidle', timeout=5000)
                except Exception:
                    pass
        return r

    def crawl(self, browser, login, password, admin):
        ctx = browser.new_context(viewport={'width': 1440, 'height': 1000})
        pg, ok = self.login(ctx, login, password)
        counts, bad, js = {}, [], []
        if not ok:
            ctx.close()
            return counts, [('index.php', 0, 'connexion impossible pour ' + login)], js, set()
        found = []
        pg.on('pageerror', lambda e: found.append((str(e)[:140], (e.stack or '')[:2000])))

        def on_dialog(dialog):
            found.append(('boîte de dialogue %s : %s' % (dialog.type, dialog.message[:120]), ''))
            dialog.dismiss()

        pg.on('dialog', on_dialog)
        pg.goto(self.base + '/front/central.php', wait_until='load')
        menu = self.menu_links(pg)
        reachable = set(menu)
        queue = ['front/central.php'] + menu + [PLUGIN + p for p in self.front_pages] + self.forms + self.core
        done, tabs_seen, crumb_links = set(), set(), set()
        while queue and len(done) < MAX_PAGES:
            name = queue.pop(0)
            if name in done:
                continue
            done.add(name)
            found.clear()
            try:
                r = self.open(pg, name, name in self.core)
            except Exception as exc:
                if 'Download is starting' in str(exc):  # téléchargement direct (PDF, CSV)
                    counts['telechargement'] = counts.get('telechargement', 0) + 1
                else:
                    bad.append((name, 'ERR', str(exc).splitlines()[0][:120]))
                continue
            st = r.status if r else 0
            counts[st] = counts.get(st, 0) + 1
            try:
                body = pg.content()
                text = pg.evaluate('document.body ? document.body.innerText.trim().length : 0')
            except Exception:
                body, text = '', 1
            m = ERR.search(body)
            ctype = (r.headers.get('content-type', '') if r else '') or 'text/html'
            if st >= 500 or st == 404 or m:
                bad.append((name, st, m.group(0) if m else ''))
            elif admin and st == 403:
                bad.append((name, st, 'refusé au super-administrateur'))
            elif st == 200 and 'html' in ctype and not text:
                bad.append((name, st, 'page blanche'))
            elif st == 200 and 'html' in ctype:
                state = self.check_crumbs(pg, name, admin, bad)
                counts['ariane ' + state] = counts.get('ariane ' + state, 0) + 1
            if st < 400:
                subpages = self.links(pg, SUBPAGES)
                crumb_links.update(self.links(pg, 'nav ol.breadcrumb a[href]'))
                if name in reachable:
                    reachable.update(subpages)
                queue += [p for p in subpages if p not in done]
                self.check_tabs(pg, admin, tabs_seen, bad, counts)
            if not found:
                continue
            first = list(found)
            found.clear()
            try:  # seconde visite : l'erreur revient-elle ?
                self.open(pg, name, name in self.core)
                pg.wait_for_timeout(1500)
            except Exception:
                pass
            for msg, stack in first + found:
                self.log.append('%s %s %s\n%s' % (login, name, msg, stack))
            if found:
                bad.append((name, 'JS', found[0][0]))
            else:
                js.append((name, first[0][0]))
        counts['pages'] = len(done)
        for old, new in self.redirects:
            try:
                r = pg.goto(self.base + '/' + old, wait_until='load')
                pg.wait_for_load_state('networkidle', timeout=5000)
            except Exception as exc:
                bad.append((old, 'REDIRECTION', str(exc).splitlines()[0][:120]))
                continue
            final = self.rel(pg.url) or pg.url
            if not final.startswith(new.lstrip('/')) or (r and r.status >= 400 and (admin or r.status != 403)):
                bad.append((old, r.status if r else 0, 'redirection attendue vers %s, obtenu %s' % (new, final)))
            else:
                counts['redirections'] = counts.get('redirections', 0) + 1
        ctx.close()
        return counts, bad, js, reachable


def main():
    args = parse_args()
    forms, menu = load_ids(args)
    crawler = Crawler(args, forms, menu)
    results, reachable = [], set()
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for i, account in enumerate(args.account):
            login, _, password = account.partition(':')
            counts, bad, js, seen = crawler.crawl(browser, login, password, i == 0)
            reachable |= seen
            results.append((login, counts, bad, js))
        browser.close()
    # Détails d'abord : les 7 dernières lignes restent le résumé lu par les scripts de publication.
    reach = {os.path.basename(urlsplit(u).path) for u in reachable}
    hidden = [p for p in crawler.front_pages if p not in reach]
    print('Pages de front/ hors menu (%d, information) : %s' % (len(hidden), ' '.join(hidden)))
    if args.log:
        with open(args.log, 'w', encoding='utf-8') as f:
            f.write('\n\n'.join(crawler.log) + '\n')
    else:
        for entry in crawler.log:
            print('JS ' + entry.splitlines()[0])
    for login, _, bad, _ in results:
        for name, st, msg in bad:
            print('::error::%s %s %s %s' % (login, name, st, msg))
    for login, counts, bad, js in results:
        print(login, dict(sorted(counts.items(), key=str)))
        print(' ANOMALIES', bad)
        print(' JS', js[:5])
    print('pages', len(crawler.front_pages))
    return 1 if any(bad for _, _, bad, _ in results) else 0


if __name__ == '__main__':
    sys.exit(main())
