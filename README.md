# GLPI GRC Manager

<p align="center"><img src="logo.png" alt="GLPI GRC Manager" width="180"></p>

> Plateforme de gouvernance, risque et conformité (GRC) et ISO 27001 générique, nativement
> intégrée à GLPI.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-stable%20%E2%80%94%20v2.0.0-brightgreen)](ROADMAP.md)
[![GLPI](https://img.shields.io/badge/GLPI-11.x-green)](docs/design/DEVELOPMENT_PLAN.md)

🇫🇷 **Français** | [🇬🇧 English](README.en.md)

## Le problème

GLPI sait exactement quels matériels, logiciels et systèmes composent votre organisation. Ce
qu'il ne sait pas faire nativement, c'est répondre aux questions d'un responsable sécurité ou
d'un auditeur ISO 27001 :

> **Quels sont les risques organisationnels (pas seulement techniques) de mon organisation, qui
> les a acceptés et pourquoi, et suis-je conforme à l'Annexe A ?**

**GLPI GRC Manager** couvre le risque organisationnel générique (clause 6.1.2/8.2 ISO 27001) :
humain, processus, physique, tiers/fournisseur, avec acceptation, traitement, Déclaration
d'Applicabilité (SoA), audits internes et actions correctives — et, depuis la v2.0, un véritable
objet ITIL "Incident de sécurité" (absorbé du plugin jumeau glpi-security-incidents, désormais
archivé), avec ses champs de classification ISO 27001 fusionnés dessus.

## Ce que le plugin apporte (voir ROADMAP.md)

- **Registre de risques génériques** : catégorie (humain/processus/physique/tiers/technique),
  probabilité, impact, niveau de risque calculé, décision de traitement (accepter/mitiger/
  transférer/éviter), propriétaire, justification, date de revue.
- **Déclaration d'Applicabilité (SoA)** : les 93 contrôles de l'Annexe A ISO 27001:2022 (clause
  6.1.3).
- **Programme d'audit interne** : non-conformités, actions correctives et préventives (CAPA).
- **Registre de risques fournisseurs/tiers.**
- **Suivi des formations de sensibilisation à la sécurité.**
- **Revues de direction.**
- **Incidents de sécurité** : objet ITIL complet dans le menu Assistance (acteurs, workflow,
  tâches, notifications, suivi CVE), avec classification ISO 27001 (catégorie, sévérité, cause
  racine/enseignements tirés obligatoires à la clôture) fusionnée dessus (Annexe A A.5.24-27).
  Chaque partie du module est activable/désactivable indépendamment (Configuration > Plugins).

## État du projet

**Sprints 1 à 7 terminés**, validés contre GLPI 11 réel : registre de risques génériques (matrice
probabilité x impact administrable, filtres, rappels de revue), Déclaration d'Applicabilité (93
contrôles Annexe A ISO/IEC 27001:2022), programme d'audit interne avec non-conformités et CAPA,
registre de risques fournisseurs/tiers, suivi des formations de sensibilisation et revues de
direction, et un tableau de bord ISMS complet (15 cartes, tableau de bord par défaut seedé à
l'installation). Le Sprint 8 (documentation et release v1.0.0, en cours) est le dernier avant la
première version publiée. Voir [ROADMAP.md](ROADMAP.md) et
[docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md) pour le détail.

## Installation

Pendant la phase de développement initiale (avant la première release), installez depuis le code
source :

```bash
cd /var/www/glpi/plugins
git clone https://github.com/parime/glpi-iso27001-management.git grcmanager
cd grcmanager
composer install --no-dev
```

Puis, depuis GLPI : Configuration > Plugins > GLPI GRC Manager > Installer > Activer.

## Documentation

📖 **[Voir le tutoriel complet](docs/TUTORIAL.md)** : le flux entier du plugin, de l'activation au
tableau de bord, en passant par le registre de risques, la matrice probabilité x impact, la SoA et
les audits/CAPA, avec une capture d'écran réelle par étape (disponible en français et en anglais).

| Document | Contenu |
|---|---|
| [docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md) | Plan de développement par sprints |
| [ROADMAP.md](ROADMAP.md) | Roadmap publique par version |
| [CHANGELOG.md](CHANGELOG.md) | Historique des évolutions |

## Compatibilité cible

- GLPI 11.x
- PHP selon la matrice de compatibilité GLPI 11 (PHP 8.2 minimum)

## Licence

Distribué sous licence [GNU GPLv3](LICENSE). Projet gratuit, communautaire, sans fonctionnalité
payante obligatoire.

## Contribuer

Les contributions sont bienvenues. Voir [CONTRIBUTING.md](CONTRIBUTING.md),
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) et [GOVERNANCE.md](GOVERNANCE.md).

## Sécurité

Pour signaler une vulnérabilité, **ne pas ouvrir d'issue publique** : voir la procédure décrite
dans [SECURITY.md](SECURITY.md).

## Support

Voir [SUPPORT.md](SUPPORT.md) pour les canaux d'aide et de discussion.
