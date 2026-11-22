"""Accessibilité de bout en bout, niveau AA du RGAA 4.1 et des WCAG 2.2 (issue #663).

Ouvre dans Chromium (Playwright) un échantillon de pages clés du plugin et y lance axe-core. Le test
échoue sur toute violation « serious » ou « critical » des règles déjà corrigées par les lots de
l'issue #663, pour qu'elles ne reviennent pas :
- contraste (WCAG 1.4.3) des badges du plugin et du titre de l'encadré « À quoi sert cette page ? » ;
- barres de progression sans nom, attributs ARIA interdits ou invalides, champs sans libellé,
  onglets et panneaux mal reliés, boutons et images sans nom, en-têtes de tableau vides.
Les éléments propres à GLPI (en-tête, menu latéral, pagination des listes, listes déroulantes
select2, champs de date, barre d'outils des tableaux de bord) sont exclus : ils ne se corrigent pas dans le plugin.

Un lot qui corrige une nouvelle règle l'ajoute à RULES ; une nouvelle page clé, à PAGES.

Usage : python3 a11y-check.py <url GLPI> <nom du thème> [identifiant] [mot de passe]
"""
import hashlib
import sys
import urllib.request

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip('/')
THEME = sys.argv[2] if len(sys.argv) > 2 else 'clair'
LOGIN = sys.argv[3] if len(sys.argv) > 3 else 'glpi'
PASSWORD = sys.argv[4] if len(sys.argv) > 4 else 'glpi'

# axe-core épinglé par version et empreinte SHA-256.
AXE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.10.2/axe.min.js'
AXE_SHA256 = 'b511cd9dec01c76f4b2ad1723b66b6db37d4c2eb4ed199076e1829d9ee7b75e3'

PAGES = [
    'dashboard.php', 'myspace.php', 'risk.php', 'control.php', 'control.csv.php', 'soascoping.php',
    'frameworkassessment.php', 'recyfrequirement.php', 'acnrequirement.php', 'referentiels.php',
    'auditprogramme.php', 'auditlog.php', 'physicalsecurity.php', 'policycoverage.php',
    'diskencryption.php',
]

# Règles structurelles corrigées (toutes les gravités « serious » et « critical » de ces règles).
RULES = [
    'aria-progressbar-name', 'aria-prohibited-attr', 'aria-valid-attr', 'aria-valid-attr-value',
    'aria-required-children', 'aria-required-parent', 'aria-required-attr', 'button-name',
    'image-alt', 'label', 'duplicate-id-aria', 'nested-interactive',
]

# Éléments de GLPI exclus de l'analyse (barre d'outils des tableaux de bord comprise).
CORE = [
    'header', 'aside', '.navbar', '.search-pager', '.select2-container', '.flatpickr-calendar',
    '.flatpickr-input', '.debug-card', '[id^="comment_link_"]', '.dashboard .toolbar',
]
# Contraste : badges du plugin et encadré d'aide, où qu'ils soient dans la page.
CONTRAST_SCOPE = ['.badge', '.grcmanager-page-help-toggle']


def axe_source():
    with urllib.request.urlopen(AXE_URL, timeout=60) as resp:
        data = resp.read()
    if hashlib.sha256(data).hexdigest() != AXE_SHA256:
        raise SystemExit('::error::empreinte inattendue pour ' + AXE_URL)
    return data.decode('utf-8')


RUN = """async ([core, rules, scope]) => {
  const structural = await axe.run({exclude: core.map(s => [s])}, {
    runOnly: {type: 'rule', values: rules}, resultTypes: ['violations']});
  const present = scope.filter(s => document.querySelector(s));
  const contrast = present.length ? await axe.run({include: present.map(s => [s]), exclude: core.map(s => [s])}, {
    runOnly: {type: 'rule', values: ['color-contrast']}, resultTypes: ['violations']}) : {violations: []};
  return structural.violations.concat(contrast.violations)
    .filter(v => v.impact === 'serious' || v.impact === 'critical')
    .map(v => ({id: v.id, impact: v.impact, nodes: v.nodes.slice(0, 5).map(n => n.target.join(' ') + ' : ' + n.html.slice(0, 160))}));
}"""


def main():
    axe = axe_source()
    failures, checked = [], 0
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
        for name in PAGES:
            resp = page.goto(BASE + '/plugins/grcmanager/front/' + name, wait_until='load')
            try:
                page.wait_for_load_state('networkidle', timeout=5000)
            except Exception:
                pass
            if not resp or resp.status != 200:
                failures.append('%s : réponse %s' % (name, resp.status if resp else 'absente'))
                continue
            page.add_script_tag(content=axe)
            for v in page.evaluate(RUN, [CORE, RULES, CONTRAST_SCOPE]):
                failures.append('%s : %s (%s) %s' % (name, v['id'], v['impact'], ' | '.join(v['nodes'])))
            checked += 1
        browser.close()
    for line in failures:
        print('::error::accessibilité, thème %s : %s' % (THEME, line))
    print('%s  %d pages, thème %s, %d violation(s) « serious » ou « critical » sur les règles corrigées'
          % ('KO' if failures else 'OK', checked, THEME, len(failures)))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
