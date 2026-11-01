"""Réduit le référentiel MITRE ATT&CK Enterprise (STIX 2.1, attack-stix-data) à ce dont grc a
besoin : tactiques et techniques actives (identifiant, nom, tactiques), pour resources/mitre-attack.json.

Usage : python -I attack_compact.py <enterprise-attack.json> <sortie.json>
"""
import json
import sys

src, out = sys.argv[1:3]
bundle = json.load(open(src, encoding='utf-8'))


def external_id(obj):
    for ref in obj.get('external_references', []):
        if ref.get('source_name') == 'mitre-attack':
            return ref.get('external_id', '')
    return ''


def active(obj):
    return not obj.get('revoked') and not obj.get('x_mitre_deprecated')


collection = next(o for o in bundle['objects'] if o['type'] == 'x-mitre-collection')
tactic_order = []
matrix = next(o for o in bundle['objects'] if o['type'] == 'x-mitre-matrix' and active(o))
tactic_refs = matrix['tactic_refs']
by_stix = {o['id']: o for o in bundle['objects']}
tactics = []
for ref in tactic_refs:
    t = by_stix[ref]
    tactics.append({'id': external_id(t), 'shortname': t['x_mitre_shortname'], 'name': t['name']})
shortnames = {t['shortname']: t['id'] for t in tactics}

techniques = []
for o in bundle['objects']:
    if o['type'] != 'attack-pattern' or not active(o):
        continue
    tid = external_id(o)
    if not tid.startswith('T'):
        continue
    phases = [shortnames[p['phase_name']] for p in o.get('kill_chain_phases', [])
              if p.get('kill_chain_name') == 'mitre-attack' and p['phase_name'] in shortnames]
    techniques.append({'id': tid, 'name': o['name'], 'tactics': phases})
techniques.sort(key=lambda t: [int(x) if x.isdigit() else x for x in t['id'][1:].split('.')])

json.dump({
    'source': 'MITRE ATT&CK Enterprise ' + collection.get('x_mitre_version', ''),
    'url': 'https://github.com/mitre-attack/attack-stix-data',
    'notice': '© The MITRE Corporation. This work is reproduced and distributed with the permission '
              'of The MITRE Corporation. https://attack.mitre.org/resources/legal-and-branding/terms-of-use/',
    'tactics': [{'id': t['id'], 'name': t['name']} for t in tactics],
    'techniques': techniques,
}, open(out, 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=0, separators=(',', ':'))
print(len(tactics), 'tactiques,', len(techniques), 'techniques')
