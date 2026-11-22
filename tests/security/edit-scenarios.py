"""Scénarios d'édition de bout en bout dans un vrai navigateur (issue #980).

Le bug #951 (gabarit d'incident de sécurité : statut, priorité et impact impossibles à régler, même
en super-admin) n'a été vu ni par la CI ni par le parcours : ils ne faisaient qu'afficher des pages.
Ici, Chromium (Playwright) ouvre les formulaires clés sur une instance qui a des données
(tests/security/setup.php), change des champs, enregistre, rouvre la fiche et vérifie :
- que les listes ne sont pas vides (urgence, impact, priorité, statut...) ;
- que l'enregistrement est conservé ;
- qu'aucune erreur JavaScript, erreur PHP ni page d'erreur n'apparaît.

En super-admin, puis avec le gestionnaire limité à l'entité A (iso-gestionnaire), qui n'a pas le droit
des gabarits : pour lui, seuls les objets de son entité.

Usage : python3 edit-scenarios.py <url GLPI> '<json de setup.php>'
"""
import json
import re
import sys
import time

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip('/')
IDS = json.loads(sys.argv[2])
P = BASE + '/plugins/grcmanager/front/'
PROFILES = [('glpi', 'glpi', True), ('iso-gestionnaire', 'Secrev#2026', False)]
ERR = re.compile(
    r'Fatal error|Uncaught|Stack trace|Twig\\Error|Undefined (variable|array key|method)|Call to undefined'
    r'|An unexpected error occurred|Une erreur inattendue', re.I)
# Numéros d'options de recherche des champs ITIL du cœur (statut, urgence, impact, priorité) : ceux
# que le gabarit de #951 ne permettait pas de prédéfinir.
TEMPLATE_FIELDS = {'status': 12, 'urgency': 10, 'impact': 11, 'priority': 3}

SET_JS = """([name, value]) => {
  const el = document.querySelector('form [name="' + name + '"]');
  if (!el) { return 'absent'; }
  el.value = value;
  if (window.jQuery) { window.jQuery(el).trigger('change'); } else { el.dispatchEvent(new Event('change')); }
  return el.value === String(value) ? 'ok' : 'refuse';
}"""
GET_JS = """(name) => {
  const el = document.querySelector('form [name="' + name + '"]');
  if (!el) { return null; }
  return {value: el.value, options: el.tagName === 'SELECT' ? Array.from(el.options).map(o => o.value) : null};
}"""


class Run:
    def __init__(self, page, who):
        self.page, self.who, self.errors, self.failures = page, who, [], []
        page.on('pageerror', lambda e: self.errors.append('erreur JS : ' + str(e)[:300]))

    def fail(self, scenario, message):
        self.failures.append('%s - %s : %s' % (self.who, scenario, message))

    def open(self, scenario, url):
        response = self.page.goto(url, wait_until='load')
        try:
            self.page.wait_for_load_state('networkidle', timeout=5000)
        except Exception:
            pass
        return self.check(scenario, response)

    def check(self, scenario, response=None):
        ok = True
        if response is not None and response.status >= 400:
            self.fail(scenario, 'HTTP %d sur %s' % (response.status, self.page.url))
            ok = False
        match = ERR.search(self.page.content())
        if match:
            self.fail(scenario, 'erreur dans la page « %s » (%s)' % (match.group(0), self.page.url))
            ok = False
        for error in self.errors:
            self.fail(scenario, error)
            ok = False
        self.errors.clear()
        return ok

    def field(self, name):
        return self.page.evaluate(GET_JS, name)

    def set(self, scenario, name, value):
        result = self.page.evaluate(SET_JS, [name, str(value)])
        if result != 'ok':
            self.fail(scenario, 'champ %s : %s (valeur %s)' % (name, result, value))
        return result == 'ok'

    def submit(self, scenario, button, scope=''):
        try:
            with self.page.expect_navigation(wait_until='load'):
                # Bouton ou <input type="submit"> ; celui d'un ticket est hors du formulaire (attribut form).
                self.page.locator('%s [name="%s"][type="submit"]:visible' % (scope, button)).first.click(timeout=15000)
        except Exception as exc:
            self.fail(scenario, 'bouton %s : %s' % (button, str(exc).splitlines()[0][:200]))
            return False
        return self.check(scenario)

    def edit(self, scenario, *args, **kwargs):
        try:
            self._edit(scenario, *args, **kwargs)
        except Exception as exc:
            self.fail(scenario, 'exception %s' % str(exc).splitlines()[0][:300])

    def _edit(self, scenario, url, texts, selects, min_options=None, button='update'):
        """Change des champs texte et des listes, enregistre, rouvre et vérifie."""
        if not self.open(scenario, url):
            return
        wanted = {}
        for name, minimum in (min_options or {}).items():
            got = self.field(name)
            if got is None or got['options'] is None:
                self.fail(scenario, 'liste %s absente' % name)
            elif len(got['options']) < minimum:
                self.fail(scenario, 'liste %s : %d choix, %d attendus au moins' % (name, len(got['options']), minimum))
        for name, prefer in selects.items():
            got = self.field(name)
            if got is None or not got['options']:
                self.fail(scenario, 'liste %s absente ou vide' % name)
                continue
            others = [v for v in got['options'] if v not in ('', '0', got['value'])]
            choice = next((v for v in prefer if v in others), others[0] if others else None)
            if choice is None:
                self.fail(scenario, 'liste %s : aucun autre choix que %s' % (name, got['value']))
                continue
            if self.set(scenario, name, choice):
                wanted[name] = choice
        for name, value in texts.items():
            if self.set(scenario, name, value):
                wanted[name] = value
        if not self.submit(scenario, button):
            return
        if not self.open(scenario, url):
            return
        for name, value in wanted.items():
            got = self.field(name)
            if got is None or got['value'] != value:
                self.fail(scenario, 'champ %s non enregistré : %s attendu, %s obtenu'
                          % (name, value, got['value'] if got else 'absent'))

    def template_fields(self, scenario, template_id):
        """#951 : prédéfinir statut, urgence, impact et priorité dans le gabarit d'incident."""
        url = (P + 'securityincidenttemplate.form.php?id=%d&forcetab=' % template_id
               + 'PluginGrcmanagerSecurityIncidentTemplatePredefinedField$1')
        for field, num in TEMPLATE_FIELDS.items():
            label = '%s (%s)' % (scenario, field)
            if not self.open(label, url):
                return
            if not self.set(label, 'num', num):
                continue
            # Le cœur charge la liste des valeurs (champ nommé comme le champ du gabarit) dans
            # #show_massiveaction_field (ajax/dropdownMassiveActionField.php).
            selector = '#show_massiveaction_field select[name="%s"]' % field
            try:
                self.page.wait_for_function(
                    "(s) => { const v = document.querySelector(s); return v && v.options.length > 1; }",
                    arg=selector, timeout=10000)
            except Exception:
                got = self.page.evaluate(
                    "(s) => { const v = document.querySelector(s); return v ? v.options.length : null; }", selector)
                self.fail(label, 'liste des valeurs vide (%s)' % ('absente' if got is None else '%d choix' % got))
                continue
            self.page.evaluate(
                "(s) => { const v = document.querySelector(s);"
                " const o = Array.from(v.options).filter(o => o.value !== '' && o.value !== '0').pop();"
                " v.value = o.value; window.jQuery(v).trigger('change'); }", selector)
            if not self.submit(label, 'add', 'form:has(#show_massiveaction_field)') or not self.open(label, url):
                continue
            # Un champ déjà prédéfini disparaît de la liste des champs proposés.
            got = self.field('num')
            if got and str(num) in (got['options'] or []):
                self.fail(label, 'champ non ajouté au gabarit')


def main():
    stamp = str(int(time.time()))
    failures = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for login, password, admin in PROFILES:
            context = browser.new_context(viewport={'width': 1440, 'height': 1000})
            page = context.new_page()
            page.set_default_timeout(60000)
            page.goto(BASE + '/index.php')
            page.fill('input[name="login_name"]', login)
            page.fill('input[type="password"]', password)
            page.click('button[type="submit"]')
            page.wait_for_load_state('load')
            if 'login_name' in page.content():
                failures.append('%s : connexion impossible' % login)
                context.close()
                continue
            run = Run(page, login)
            if admin:
                try:
                    run.template_fields('gabarit d\'incident de sécurité', IDS['incident_template'])
                except Exception as exc:
                    run.fail('gabarit d\'incident de sécurité', 'exception %s' % str(exc).splitlines()[0][:300])
                run.edit('ticket', BASE + '/front/ticket.form.php?id=%d' % IDS['ticket_A'],
                         {}, {'urgency': ['4', '2'], 'impact': ['4', '2']}, {'urgency': 5, 'impact': 5})
            # Statut « En attente » : un statut « En cours (attribué) » sans technicien revient à « Nouveau ».
            run.edit('incident de sécurité', P + 'securityincident.form.php?id=%d' % IDS['incident_A'],
                     {'external_id': 'EXT-' + stamp},
                     {'status': ['4'], 'urgency': ['4', '2'], 'impact': ['4', '2']},
                     {'status': 2, 'urgency': 5, 'impact': 5, 'priority': 5})
            run.edit('risque', P + 'risk.form.php?id=%d' % IDS['risk'],
                     {'external_ref': 'REF-' + stamp}, {'treatment': []})
            run.edit('mesure', P + 'control.form.php?id=%d' % IDS['control'],
                     {'justification': 'Justification ' + stamp}, {'implementation_status': []})
            run.edit('non-conformité', P + 'nonconformity.form.php?id=%d' % IDS['nc_A'],
                     {'audited_process': 'Processus ' + stamp}, {'severity': []})
            run.edit('évaluation fournisseur', P + 'supplierevaluation.form.php?id=%d' % IDS['evaluation_A'],
                     {'comment': 'Commentaire ' + stamp}, {})
            failures += run.failures
            context.close()
        browser.close()
    for failure in failures:
        print('::error::' + failure)
    print('%d anomalie(s) dans les scénarios d\'édition' % len(failures))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
