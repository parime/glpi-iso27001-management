# GLPI GRC Manager

<p align="center"><img src="logo.png" alt="GLPI GRC Manager" width="180"></p>

> Generic Governance, Risk and Compliance (GRC) and ISO 27001 platform, natively integrated into
> GLPI.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-stable%20%E2%80%94%20v2.2.0-brightgreen)](ROADMAP.md)
[![GLPI](https://img.shields.io/badge/GLPI-11%20%7C%2012-green)](docs/design/DEVELOPMENT_PLAN.md)

[🇫🇷 Français](README.md) | 🇬🇧 **English**

## The problem

GLPI knows exactly which hardware, software and systems make up your organization. What it can't
natively do is answer the questions a security officer or an ISO 27001 auditor actually asks:

> **What are my organization's risks, not just the technical ones, who accepted them and why, and
> am I compliant with Annex A?**

**GLPI GRC Manager** covers generic organizational risk (ISO 27001 clause 6.1.2/8.2): people,
process, physical, third-party, with acceptance, treatment, a Statement of Applicability (SoA),
internal audits and corrective actions — and, since v2.0, a real "Security Incident" ITIL object
(absorbed from the sibling plugin glpi-security-incidents, now archived), with its ISO 27001
classification fields merged directly onto it.

## What the plugin brings (see ROADMAP.md)

- **Generic risk register**: category (people/process/physical/third-party/technical),
  probability, impact, computed risk level, treatment decision (accept/mitigate/transfer/avoid),
  owner, justification, review date.
- **Statement of Applicability (SoA)**: the 93 ISO 27001:2022 Annex A controls (clause 6.1.3).
- **Management approval of the SoA**: frozen, dated version (PDF kept in GLPI documents with its
  SHA-256 fingerprint), designated approvers who approve from GLPI, version history; any later
  change to the SoA requires a new version.
- **ISMS requirements (clauses 4 to 10)**: the 30 sub-clauses 4.1 to 10.2, with status, owner,
  evidence (linked GLPI documents), a link to the module that already covers them and a
  completeness rate, included in the PDF export.
- **Internal audit program**: non-conformities, corrective and preventive actions (CAPA).
- **Supplier/third-party risk register.**
- **Security awareness training tracking.**
- **Management reviews.**
- **Security incidents**: a full ITIL object in the Assistance menu (actors, workflow, tasks,
  notifications, CVE tracking), with ISO 27001 classification (category, severity, root cause/
  lessons learned required at closure) merged onto it (Annex A A.5.24-27). Each part of the module
  can be enabled/disabled independently (Configuration > Plugins).

## Project status

**Stable v2.2.0**, published and installable right now: generic risk register (administrable
probability x impact matrix, interactive heatmap, filters, review reminders), Statement of
Applicability (93 ISO/IEC 27001:2022 Annex A controls), internal audit program with
non-conformities and CAPA, supplier/third-party risk register, security awareness training
tracking (including assessment pass rate) and management reviews, a full Security Incidents module
(ITIL object, CVE tracking, absorbed from the sibling plugin `glpi-security-incidents` in v2.0.0),
and a complete ISMS dashboard. See [ROADMAP.md](ROADMAP.md) for what's planned next and
[CHANGELOG.md](CHANGELOG.md) for the full history of published versions.

## Installation

**1. Get the code** into your GLPI's `plugins/` directory, under the name **`grcmanager`** (GLPI
derives the plugin key from it):

- From a [release](https://github.com/parime/glpi-iso27001-management/releases) (recommended, no
  dependency to install — `vendor/` is already bundled in the ZIP): download
  `glpi-iso27001-management-X.Y.Z.zip`, extract it into `plugins/`, then rename the extracted
  folder to `grcmanager`.
- Or for development, from source:

```bash
cd /var/www/glpi/plugins
git clone https://github.com/parime/glpi-iso27001-management.git grcmanager
cd grcmanager
composer install --no-dev
```

**2. Install and enable it**, from the UI (**Setup > Plugins**, "GLPI GRC Manager") or from the
command line:

```bash
php bin/console plugin:install grcmanager
php bin/console plugin:activate grcmanager
```

## Documentation

📖 **[See the full tutorial](docs/TUTORIAL.md)**: the plugin's whole flow, from activation to the
dashboard, through the risk register, the probability x impact matrix, the SoA and audits/CAPA,
with a real screenshot for every step (available in French and English).

| Document | Content |
|---|---|
| [docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md) | Sprint-by-sprint development plan |
| [ROADMAP.md](ROADMAP.md) | Public roadmap by version |
| [CHANGELOG.md](CHANGELOG.md) | Change history |

## Target compatibility

- GLPI 11.x and GLPI 12.x (a single package for both versions)
- PHP per the compatibility matrix of the GLPI version in use (PHP 8.2 minimum)

## Disclaimer: ISO 27001 and third-party frameworks

**This project is not affiliated with, endorsed by or certified by ISO (International
Organization for Standardization), IEC or AFNOR.** The "ISO 27001" wording in the repository name
and documentation only identifies the framework the plugin helps you track.

- **What the plugin contains**: only the **references** of the 93 ISO/IEC 27001:2022 Annex A
  controls (A.5.1 to A.8.34), their theme and a **short title** per control, plus the numbers of
  sub-clauses 4.1 to 10.2 with a short title written by this project.
- **What it does not contain**: the control text, the ISO/IEC 27002 implementation guidance, or
  the text of the requirements (clauses 4 to 10). Justification and implementation fields are
  written by your organisation.
- **The standard must be purchased** from [ISO](https://www.iso.org/standard/27001) or your
  national standards body (e.g. [AFNOR](https://www.boutique.afnor.org/)): the official text of
  ISO/IEC 27001 (requirements) and ideally ISO/IEC 27002 (implementation guidance) is required to
  implement an ISMS and seek certification.
- **The plugin does not grant any certification**: certification is issued by an accredited
  certification body. The plugin helps you prepare for the audit, nothing more.

**Additional reference frameworks viewable in the plugin:**

- **CIS Critical Security Controls® v8**: the titles of the 18 controls and 153 Safeguards are
  reproduced unmodified, in English, from the publication of the
  [Center for Internet Security, Inc.](https://www.cisecurity.org/controls), licensed under
  [Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International (CC BY-NC-ND 4.0)](https://creativecommons.org/licenses/by-nc-nd/4.0/).
  © Center for Internet Security, Inc. CIS Controls® and CIS Critical Security Controls® are
  trademarks of the Center for Internet Security, Inc. This project is not affiliated with or
  endorsed by CIS. This excerpt is not covered by the plugin's GPLv3 license and may not be reused
  for commercial purposes.
- **NIST Cybersecurity Framework (CSF) 2.0**: published by the
  [National Institute of Standards and Technology](https://www.nist.gov/cyberframework)
  (U.S. federal government), in the public domain.
- **Directive (EU) 2022/2555 "NIS2"**: articles 20, 21(2) and 23(4). The ten measures of
  article 21(2) are reproduced from the official French and English language versions published
  on [EUR-Lex](https://eur-lex.europa.eu/eli/dir/2022/2555/oj); articles 20 and 23 are
  summarised. © European Union, https://eur-lex.europa.eu/ — reuse authorised (Commission
  Decision 2011/833/EU). Only the version published in the Official Journal of the EU is
  authentic. The mapping to ISO 27001 Annex A is indicative and made by this project.
- **ANSSI IT hygiene guide (42 measures)**: titles of the 42 measures and 10 themes reproduced
  from the guide "Renforcer la sécurité de son système d'information en 42 mesures", version 2.0
  of September 2017, published by
  [ANSSI](https://cyber.gouv.fr/publications/guide-dhygiene-informatique) (French national
  cybersecurity agency) under the
  [Licence Ouverte / Open Licence (Etalab)](https://www.etalab.gouv.fr/licence-ouverte-open-licence/).
  The English translation and the Annex A mapping are indicative and made by this project; this
  project is not affiliated with or endorsed by ANSSI.

## License

Distributed under the [GNU GPLv3](LICENSE) license. Free, community-driven project, with no
mandatory paid feature. Excerpts of third-party frameworks (CIS Controls, NIST CSF, NIS2, ANSSI guide) remain under
their original license: see the disclaimer above.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md),
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) and [GOVERNANCE.md](GOVERNANCE.md).

## Security

To report a vulnerability, **do not open a public issue**: see the procedure described in
[SECURITY.md](SECURITY.md).

## Support

See [SUPPORT.md](SUPPORT.md) for help and discussion channels.
