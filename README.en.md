# GLPI GRC Manager

<p align="center"><img src="logo.png" alt="GLPI GRC Manager" width="180"></p>

> Generic Governance, Risk and Compliance (GRC) and ISO 27001 platform, natively integrated into
> GLPI.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-stable%20%E2%80%94%20v1.1.4-brightgreen)](ROADMAP.md)
[![GLPI](https://img.shields.io/badge/GLPI-11.x-green)](docs/design/DEVELOPMENT_PLAN.md)

[🇫🇷 Français](README.md) | 🇬🇧 **English**

## The problem

GLPI knows exactly which hardware, software and systems make up your organization. What it can't
natively do is answer the questions a security officer or an ISO 27001 auditor actually asks:

> **What are my organization's risks, not just the technical ones, who accepted them and why, and
> am I compliant with Annex A?**

**GLPI GRC Manager** covers generic organizational risk (ISO 27001 clause 6.1.2/8.2): people,
process, physical, third-party, with acceptance, treatment, a Statement of Applicability (SoA),
internal audits and corrective actions — distinct from security incident and CVE tracking, which
is handled by the [glpi-security-incidents](https://github.com/parime/glpi-security-incidents)
plugin.

## What the plugin brings (v1.0 vision, see ROADMAP.md)

- **Generic risk register**: category (people/process/physical/third-party/technical),
  probability, impact, computed risk level, treatment decision (accept/mitigate/transfer/avoid),
  owner, justification, review date.
- **Statement of Applicability (SoA)**: the 93 ISO 27001:2022 Annex A controls (clause 6.1.3).
- **Internal audit program**: non-conformities, corrective and preventive actions (CAPA).
- **Supplier/third-party risk register.**
- **Security awareness training tracking.**
- **Management reviews.**

## Project status

**Sprints 1 through 7 complete**, validated against a real GLPI 11: generic risk register
(administrable probability x impact matrix, filters, review reminders), Statement of Applicability
(93 ISO/IEC 27001:2022 Annex A controls), internal audit program with non-conformities and CAPA,
supplier/third-party risk register, security awareness training tracking and management reviews,
and a complete ISMS dashboard (15 cards, a default dashboard seeded at install time). Sprint 8
(documentation and the v1.0.0 release, in progress) is the last one before the first published
version. See [ROADMAP.md](ROADMAP.md) and
[docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md) for the details.

## Installation

During the initial development phase (before the first release), install from source:

```bash
cd /var/www/glpi/plugins
git clone https://github.com/parime/glpi-iso27001-management.git grcmanager
cd grcmanager
composer install --no-dev
```

Then, from GLPI: Setup > Plugins > GLPI GRC Manager > Install > Enable.

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

- GLPI 11.x
- PHP per the GLPI 11 compatibility matrix (PHP 8.1 minimum)

## License

Distributed under the [GNU GPLv3](LICENSE) license. Free, community-driven project, with no
mandatory paid feature.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md),
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) and [GOVERNANCE.md](GOVERNANCE.md).

## Security

To report a vulnerability, **do not open a public issue**: see the procedure described in
[SECURITY.md](SECURITY.md).

## Support

See [SUPPORT.md](SUPPORT.md) for help and discussion channels.
