# GLPI GRC Manager

<p align="center"><img src="logo.png" alt="GLPI GRC Manager" width="180"></p>

> Generic Governance, Risk and Compliance (GRC) and ISO 27001 platform, natively integrated into
> GLPI.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![Release](https://img.shields.io/github/v/release/parime/glpi-iso27001-management?label=version)](https://github.com/parime/glpi-iso27001-management/releases/latest)
[![GLPI](https://img.shields.io/badge/GLPI-11%20%7C%2012-green)](docs/design/DEVELOPMENT_PLAN.md)

[🇫🇷 Français](README.md) | 🇬🇧 **English**

## The problem

GLPI knows exactly which hardware, software and systems make up your organization. What it can't
natively do is answer the questions a security officer or an ISO 27001 auditor actually asks:

> **What are my organization's risks, not just the technical ones, who accepted them and why, and
> am I compliant with Annex A?**

**GLPI GRC Manager** covers generic organizational risk (ISO 27001 clause 6.1.2/8.2): people,
process, physical, third-party, with acceptance, treatment, a Statement of Applicability (SoA),
internal audits and corrective actions: and, since v2.0, a real "Security Incident" ITIL object
(absorbed from the sibling plugin glpi-security-incidents, now archived), with its ISO 27001
classification fields merged directly onto it.

## What the plugin brings (see ROADMAP.md)

**Risks**
- **Generic risk register**: category (people/process/physical/third-party/technical),
  probability, impact, computed risk level, treatment decision (accept/mitigate/transfer/avoid),
  owner, justification, review date; administrable probability x impact matrix and interactive
  heatmap.
- **Supplier/third-party risk register**, with a **security assessment questionnaire** (score,
  the supplier's GLPI contracts, evidence, reassessment ticket when due).

**Compliance**
- **Statement of Applicability (SoA)**: the 93 ISO 27001:2022 Annex A controls (clause 6.1.3),
  PDF export.
- **Management approval of the SoA**: frozen, dated version (PDF kept in GLPI documents with its
  SHA-256 fingerprint), designated approvers who approve from GLPI, version history; any later
  change to the SoA requires a new version.
- **ISMS requirements (clauses 4 to 10)**: the 30 sub-clauses 4.1 to 10.2, with status, owner,
  evidence (linked GLPI documents), a link to the module that already covers them and a
  completeness rate, included in the PDF export.
- **NIS2** (Directive (EU) 2022/2555) and the **ANSSI IT hygiene guide** frameworks, mapped to
  Annex A.
- **DORA** framework (Regulation (EU) 2022/2554, financial sector), optional and disabled by
  default: requirements of 19 key articles (5 to 45) summarised, mapped to Annex A, references shown on the
  controls and DORA compliance rate.
- **ISO/IEC 27701:2025 privacy**, optional and disabled by default: Annex A objectives by role
  (PII controller, PII processor, shared security) summarised, mapped to ISO 27001 Annex A and to the
  GDPR modules of the plugin (record of processing, DPIA, data subject requests, incidents,
  suppliers), references shown on the controls and ISO 27701 compliance rate.
- **ISO 22301:2019 business continuity**, optional and disabled by default: main requirements of
  clauses 4 to 10 summarised, mapped to ISO 27001 Annex A and to the plugin modules (risks, crisis
  exercises, incidents, suppliers, audits, management review), references on the controls and
  ISO 22301 compliance rate.
- **ISO/IEC 42001:2023 AI management**, optional and disabled by default: the nine control groups of
  Annex A (A.2 to A.10) summarised and gathered by theme, mapped to ISO 27001 Annex A and to the
  plugin modules (typical AI risks, DPIA, record of processing, incidents, suppliers), references on
  the controls and ISO 42001 compliance rate. Complements ISO/IEC 27090 (AI security).
- **PCI DSS v4.0.1 payment cards**, optional and disabled by default: the twelve principal
  requirements summarised and gathered by objective, mapped to ISO 27001 Annex A and to the plugin
  modules (network flows, account hygiene, vulnerabilities, access reviews, policies, trainings,
  suppliers, risks, incidents), references on the controls and PCI DSS rate.
- **SOC 2 (Trust Services Criteria 2017)**, optional and disabled by default: common criteria CC1 to
  CC9 and additional criteria (availability, confidentiality, processing integrity, privacy)
  summarised, mapped to ISO 27001 Annex A and to the modules that produce the evidence, references
  on the controls and SOC 2 rate.
- **AI Act (Regulation (EU) 2024/1689)**, optional and disabled by default: consolidated version of
  27/07/2026 (digital omnibus 2026/1744 included), obligations by role (any organisation, deployers,
  providers of high-risk systems) with their application date, mapped to ISO 27001 Annex A and to the
  plugin modules, references on the controls and AI Act rate.
- **HDS v2.0 (French health data hosting)**, optional and disabled by default: the 31 requirements
  of the certification framework (ISMS, customer contract clauses, data sovereignty), mapped to ISO
  27001 Annex A and to the plugin modules, "HDS EXI n" badges on the controls and HDS rate.
- **Custom frameworks**: a framework that is not built in (group requirements, a customer security
  assurance plan) is imported from a spreadsheet saved as CSV, from a downloadable template, with a
  report of the rejected lines. It can be enabled like the others, is measured through the Annex A
  controls it cites, and each requirement can be self-assessed; a new version of the file updates it
  without losing the statuses already entered.
- **Security of AI systems (ISO/IEC 27090:2026)**: sections of the standard mapped to Annex A, and
  typical AI risks (public AI services, prompt injection, data leaks, supply chain, poisoning,
  model theft, unlisted AI systems) to import into the register from the risk catalog.
- **Evidence with an expiry date**: renewal owner, reminder before the due date, "Renew the
  evidence" ticket on expiry.

**Audit and improvement**
- **Internal audit program**: non-conformities, corrective and preventive actions (CAPA).
- **Management reviews.**

**People and access**
- **Security awareness training tracking**, including the assessment pass rate.
- **Periodic access review**: campaigns approved by managers, one ticket per requested change or
  removal, PDF report archived on control A.5.18.
- With the [assetsign](https://github.com/parime/assetsign-glpi) plugin: signed equipment
  handovers and returns as evidence for controls A.5.9 to A.5.11, and the IT charter signature
  rate on A.5.10 and A.6.3.
- With the [Wazuh](https://github.com/initiativa/wazuh) plugin (optional, read only): active agents
  and share of computers covered as evidence for controls A.8.16 and A.8.7, and a "Wazuh coverage"
  dashboard card. Without the plugin, nothing changes.

**Security incidents**
- A full ITIL object in the Assistance menu (actors, workflow, tasks, notifications, CVE
  tracking), with ISO 27001 classification (category, severity, root cause/lessons learned
  required at closure) merged onto it (Annex A A.5.24-27).
- Turning a ticket into a security incident, and **NIS2 (24 h, 72 h, 1 month) and GDPR (72 h)
  notification deadlines** tracked by a scheduled task.
- Each part of the module can be enabled/disabled independently (Configuration > Plugins).

**Vulnerabilities and continuity**
- **Fleet vulnerabilities**: CVEs of installed software (NVD), real-world exploitation (CISA KEV,
  FIRST EPSS), CERT-FR advisories, one update ticket per software (automatic creation off by
  default), "Vulnerabilities" tab on computers.
- EPSS/KEV tracking of incident CVEs, and an **action plan (CAPA) proposed** from a CVE.
- **Outage impact** (GLPI impact analysis), single points of failure, **RTO/RPO** recovery
  objectives and an export for the business continuity plan.
- **Evidence coverage**: which assets must have which document.
- **Supplier security watch** (CERT-FR advisories naming the supplier), **typical infrastructure
  risk catalogue**, **domino effect** between risks and incidents, **security exemptions**.
- **Business processes and business impact analysis (BIA)**: owner, criticality, MTPD, RTO, RPO,
  impact by duration of interruption, GLPI items and other processes each process depends on,
  assets and processes slower than the process reported, linked risks (and a risk proposed for a
  slow asset), recovery strategy and measured tests, dependency map, reminders of BIAs to review
  and recovery tests due, dashboard indicators, business continuity plan as PDF and CSV export.
- **Crisis exercises** (ransomware, data leak, outage): fictitious incident, timed steps, exercise
  report archived as evidence (A.5.29, A.5.30).
- **Crisis team** on a real incident: response plan, recovery of affected items against their RTO,
  crisis log and report.
- **Post-incident report** as a PDF (timeline, NIS2/GDPR notifications, lessons learned), archived as
  evidence (A.5.27).

**Steering**
- Default ISMS dashboard and KPIs to add to GLPI dashboards, including compliance by framework
  (ISO 27001, NIS2, ANSSI, NIST CSF, CIS Controls, and DORA, ISO 27701, ISO 22301, ISO 42001, PCI DSS, SOC 2, AI Act, HDS when enabled), deadlines within 90 days, access review progress and NIS2 / GDPR
  deadlines at risk; GRC & Compliance > Dashboard menu, ISMS and Management views, monthly trend.
- **Scored trainings**, in one or several sessions (trainer, location, score, pass threshold, retake,
  PDF report with attendance sheet, each participant's quiz copy restricted to the participant, the
  trainer and the CISO), and **internal audits** with findings, working notes, audit team, documents
  restricted to the team (downloads logged) and a PDF report archived on closure.
- **Network flow matrix**: import of a Stormshield policy export and of a pfSense backup (named
  zones, port forwards linked to their rule, IPsec tunnels as site-to-site flows), zone by zone
  matrix view, partner networks conflicting with an internal
  network, PDF report for the auditor.
- **My GRC space**: a personal page with everything waiting for the logged-in user (risks and
  treatment actions, control tests, evidence to renew, nonconformities, periodic and access reviews,
  policies, trainings, SoA approvals...), sorted by due date, overdue items highlighted, number of
  waiting items in the menu. Nothing the user is not allowed to see.
- **Audit evidence requests**: list of the evidence requested by the auditor (entered or imported
  from CSV), assignee and due date, submission from "My evidence requests" without any right on
  the plugin, review (accepted or to complete with a reason), reminders, progress; read-only access
  for an auditor limited to one audit and in time, submitted documents only, downloads recorded.
- **DORA register of information** (DORA framework enabled): the register of contracts with ICT
  third-party service providers required by Article 28(3) of Regulation (EU) 2022/2554, built from
  GLPI contracts, suppliers and business processes without duplicating them. "DORA register" tab on
  the contract (type of arrangement, services S01 to S19, notice periods, governing law, storage and
  processing locations, sensitiveness, reliance, substitutability, exit plan, supported functions,
  subcontracting chain with computed ranks), on the supplier (LEI or other code, headquarters,
  group) and on the business process (critical or important function, licensed activity; RTO, RPO
  and impact taken from the BIA). Required fields are checked before the export, with a link to the
  record to complete. **Export**: one CSV per template of Implementing Regulation (EU) 2024/2956
  (B_01.01 to B_99.01), c0010... headers, UTF-8, comma, in a `reports/` archive as in an xBRL-CSV
  package. **Still to do by hand**: branches (B_01.03), intra-group arrangements (B_02.03, B_03.03)
  and group entities (B_01.02 only holds the entity maintaining the register, B_04.01 declares no
  branch), internal definitions of B_99.01, conversion of closed lists (written with their official
  English wording) into EBA taxonomy codes and the package files (report.json, parameters, filing
  indicators), by your reporting tool. Sources:
  [Regulation (EU) 2022/2554](https://eur-lex.europa.eu/eli/reg/2022/2554/oj),
  [Implementing Regulation (EU) 2024/2956](https://eur-lex.europa.eu/eli/reg_impl/2024/2956/oj).
- **Policy acknowledgement**: target audience (groups, profiles, entities) and response deadline on
  each approved policy, one campaign per version, confirmation by each person from their
  preferences or My GRC space, rate per policy and per entity, weekly reminders, list of latecomers
  (CSV, PDF) archived as evidence on control A.5.1.
- Cyber **maturity score** (0 to 100) with the calculation details and its monthly history.
- **Assessment by framework**: for each enabled framework, implementation rate, evidence rate
  (unexpired documents attached to the controls) and score of the controls both done and proven,
  with the list of gaps leading to each control.
- **Record of processing activities (GDPR, article 30)**: one entry per processing (purpose, legal basis,
  data subjects and data, recipients, transfers outside the EU, retention, security measures), linked to GLPI
  applications, assets and suppliers and to SoA controls, with its gaps, a yearly review and a PDF or CSV
  export for the supervisory authority. Dedicated right "Personal data protection" (plus read on the general
  right to see the menu entry). DPIA and data subject requests to come.
- **Automated technical checks**: ISO 27001, NIS2 and GDPR score from GLPI data (antivirus,
  encryption, inventory, change validation, accounts) and one remediation ticket per check.
- Every menu screen explains what it is for and what is expected. Screens that belong to the same
  task are grouped into sections (one menu entry, shared tabs), in the order of the ISMS cycle.

**Common principle**: the plugin never changes a status, a right or an assignment by itself. It
creates the ticket or the notification, and the team decides.

## Menu layout

The **GRC & Conformité** menu has six sections, in the order of the ISMS cycle. Each section opens
its first page; the other pages of the section are reached through the tab bar at the top of the
page and through the breadcrumb. Right after the dashboard, **My GRC space** shows the number of
items waiting for the user.

| Section | Pages |
|---|---|
| Dashboard | Dashboard, Maturity score, Assessment by framework, Compliance calendar |
| Risks | Risks, Supplier risks, Impact analysis, Domino effect, Waivers |
| Compliance | Annex A controls (SoA), Reference frameworks, ISMS requirements, Obligations, Record of processing activities, Policies, Evidence, Evidence coverage |
| Audits and improvement | Internal audits, Non-conformities, ISMS objectives, Management reviews |
| Operations | Access reviews, Account hygiene, Periodic reviews, Trainings, Crisis exercises, Business processes |
| Technical security | Automated technical checks, Fleet vulnerabilities, Most exposed machines, Software to watch, Products, Network flow matrix |

Security incidents live in **Assistance**, next to tickets. Plugin rights are granted to any profile
from the **GRC Manager** tab of Administration > Profiles.

## Sensitive registers

The risk register, fleet vulnerabilities and network flow matrix describe the organisation's weak
points: an ideal map for an attacker. The plugin protects them at its own level:

- a **dedicated right**, "Registres sensibles", separate from the general ISMS right: give it to the
  CISO and risk owners only (Administration > Profiles, GRC Manager tab). Without it, these registers
  appear nowhere, neither in other records nor in the dashboard;
- **history** of risk changes, and a **trace of register exports** in GLPI's event log;
- **e-mails without details** by default ("Risk no. X" and the link to the record).
## Project status

**Stable**, published and installable right now, for GLPI 11 and 12: see the
[latest release](https://github.com/parime/glpi-iso27001-management/releases/latest) and the
[CHANGELOG](CHANGELOG.md) for the full history. Next steps: see [ROADMAP.md](ROADMAP.md).

## Installation

**1. Get the code** into your GLPI's `plugins/` directory, under the name **`grcmanager`** (GLPI
derives the plugin key from it):

- From a [release](https://github.com/parime/glpi-iso27001-management/releases) (recommended, no
  dependency to install - `vendor/` is already bundled in the ZIP): download
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
- User interface in 6 languages: French, English, German, Spanish, Italian and Brazilian
  Portuguese. Annex A control titles in languages other than French and English are this
  project's translation, not the text of the national editions of the standard.

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
  for commercial purposes. The mapping between the 18 controls and ISO 27001 Annex A, used for the
  CIS score, is indicative and made by this project: it is not taken from CIS.
- **NIST Cybersecurity Framework (CSF) 2.0**: published by the
  [National Institute of Standards and Technology](https://www.nist.gov/cyberframework)
  (U.S. federal government), in the public domain.
- **Directive (EU) 2022/2555 "NIS2"**: articles 20, 21(2) and 23(4). The ten measures of
  article 21(2) are reproduced from the official French and English language versions published
  on [EUR-Lex](https://eur-lex.europa.eu/eli/dir/2022/2555/oj); articles 20 and 23 are
  summarised. © European Union, https://eur-lex.europa.eu/ - reuse authorised (Commission
  Decision 2011/833/EU). Only the version published in the Official Journal of the EU is
  authentic. The mapping to ISO 27001 Annex A is indicative and made by this project.
- **Regulation (EU) 2022/2554 "DORA"**: titles of 19 key articles (5 to 45) from the official French and
  English language versions published on
  [EUR-Lex](https://eur-lex.europa.eu/eli/reg/2022/2554/oj); the requirements are summarised by this
  project, not reproduced. © European Union, https://eur-lex.europa.eu/ - reuse authorised
  (Commission Decision 2011/833/EU). Only the version published in the Official Journal of the EU is
  authentic. The mapping to ISO 27001 Annex A is indicative and made by this project.
- **ISO/IEC 27701:2025**: standard protected by copyright (© ISO/IEC). Only the table and
  objective numbers of Annex A are used; their purpose is summarised by this project and the mapping
  to ISO 27001 Annex A is indicative. This project is not affiliated with ISO or IEC:
  [iso.org](https://www.iso.org/standard/27701).
- **ISO 22301:2019**: standard protected by copyright (© ISO). Only the clause numbers are used;
  their purpose is summarised by this project and the mapping to ISO 27001 Annex A is indicative.
  This project is not affiliated with ISO: [iso.org](https://www.iso.org/standard/75106.html).
- **ISO/IEC 42001:2023**: standard protected by copyright (© ISO/IEC). Only the Annex A group
  numbers are used; themes and summaries are written by this project and the mapping to ISO 27001
  Annex A is indicative. This project is not affiliated with ISO or IEC:
  [iso.org](https://www.iso.org/standard/81230.html).
- **PCI DSS v4.0.1**: standard protected by copyright (© PCI Security Standards Council). Only the
  numbers of the twelve requirements are used; summaries and grouping are written by this project
  and the mapping to ISO 27001 Annex A is indicative. This project is not affiliated with the PCI SSC:
  [pcisecuritystandards.org](https://www.pcisecuritystandards.org/document_library/).
- **Regulation (EU) 2024/1689 "AI Act"**: official article titles from the consolidated version
  published on [EUR-Lex](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:02024R1689-20260727);
  obligations are summarised by this project. © European Union, https://eur-lex.europa.eu/ - reuse
  authorised (Commission Decision 2011/833/EU). Only the version published in the Official Journal of
  the EU is authentic. The mapping to ISO 27001 Annex A is indicative.
- **HDS certification framework v2.0**: published by the French Digital Health Agency
  ([esante.gouv.fr](https://esante.gouv.fr/offres-services/hds/certification-hebergeur-de-donnees-de-sante)),
  approved by the order of 26 April 2024, public document. Requirement numbers are the official
  ones; requirements are summarised by this project and the mapping to ISO 27001 Annex A is
  indicative. Only the framework published by the ANS is authentic.
- **SOC 2 / Trust Services Criteria**: criteria protected by copyright (© AICPA). Only their
  identifiers are used; summaries are written by this project and the mapping to ISO 27001 Annex A
  is indicative (it does not reuse the AICPA one). This project is not affiliated with the AICPA:
  [aicpa-cima.com](https://www.aicpa-cima.com/topic/audit-assurance/audit-and-assurance-greater-than-soc-2).
- **ANSSI IT hygiene guide (42 measures)**: titles of the 42 measures and 10 themes reproduced
  from the guide "Renforcer la sécurité de son système d'information en 42 mesures", version 2.0
  of September 2017, published by
  [ANSSI](https://cyber.gouv.fr/publications/guide-dhygiene-informatique) (French national
  cybersecurity agency) under the
  [Licence Ouverte / Open Licence (Etalab)](https://www.etalab.gouv.fr/licence-ouverte-open-licence/).
  The English translation and the Annex A mapping are indicative and made by this project; this
  project is not affiliated with or endorsed by ANSSI.
- **ISO/IEC 27090:2026** (security of AI systems): copyrighted document (© ISO/IEC 2026). Only
  its section numbers are used; the summary of each section, the typical AI risks and the mapping
  to ISO 27001 Annex A are written by this project, which is not affiliated with ISO or IEC. The
  text of the standard is available from [ISO](https://www.iso.org/standard/27090) or from your
  national standards body.

## License

Distributed under the [GNU GPLv3](LICENSE) license. Free, community-driven project, with no
mandatory paid feature. Excerpts of third-party frameworks (CIS Controls, NIST CSF, NIS2, DORA, ANSSI guide) remain under
their original license: see the disclaimer above.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md),
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) and [GOVERNANCE.md](GOVERNANCE.md).

## Security

To report a vulnerability, **do not open a public issue**: see the procedure described in
[SECURITY.md](SECURITY.md).

## Support

See [SUPPORT.md](SUPPORT.md) for help and discussion channels.
