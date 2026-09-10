# Roadmap publique

Cette roadmap donne la vision produit par version majeure. Le détail sprint par sprint de la
version en cours se trouve dans
[docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md). Périmètre initial issu de la
décision prise dans l'issue [#89 du plugin jumeau glpi-vulnerability-manager](https://github.com/parime/glpi-vulnerability-manager/issues/89).

## Version 1.0 : Première version utilisable

Objectif : une plateforme GRC/ISO 27001 générique fonctionnelle de bout en bout.

- Registre de risques génériques (clause 6.1.2/8.2 ISO 27001), avec matrice probabilité x impact
  administrable
- Déclaration d'Applicabilité (SoA), 93 contrôles Annexe A ISO 27001:2022 (clause 6.1.3)
- Acceptation de risque avec propriétaire, justification, date de revue
- Programme d'audit interne : non-conformités et observations/remarques distinctes (vocabulaire
  ISO 19011, issue #27), actions correctives et préventives (CAPA)
- Registre de risques fournisseurs/tiers
- Suivi des formations de sensibilisation à la sécurité
- Revues de direction
- Dashboards technique et RSSI de base
- Documentation utilisateur, administrateur et développeur complète
- Lien optionnel entre un risque et un ou plusieurs actifs réels de la CMDB GLPI (issue #25,
  constat d'un audit ISO 27001 du plugin lui-même : le registre de risques était complètement
  déconnecté de la CMDB)
- Classification Confidentialité/Intégrité/Disponibilité (C/I/D) des actifs réels de la CMDB GLPI
  (issue #26, clauses A.5.9/A.5.12/A.8.2 ISO/IEC 27001:2022), registre indépendant du lien
  risque <-> actif ci-dessus : une classification est une propriété de l'actif lui-même, pas d'un
  risque particulier
- Bibliothèque de politiques de sécurité versionnées (issue #28, clause A.5.1 ISO/IEC 27001:2022) :
  cycle de vie brouillon/approuvée/archivée, version, date d'approbation, rappels de revue
  automatiques, document(s) joint(s) via le mécanisme natif GLPI Document/Document_Item
- Registre des obligations légales, réglementaires et contractuelles (issue #30, clause 4.2/Annexe A
  A.5.31-36 ISO/IEC 27001:2022), avec lien optionnel vers une entrée du registre de risques quand le
  non-respect d'une obligation constitue un risque identifié
- Objectifs ISMS et suivi de KPI dans le temps (issue #32, clause 6.2 ISO 27001) : fixer des
  objectifs de sécurité mesurables et suivre leur trajectoire dans le temps (historique de mesures
  manuel), lié aux revues de direction existantes
- Registre des incidents de sécurité de l'information (issue #29, Annexe A A.5.24-27 ISO/IEC
  27001:2022) : classification (catégorie, sévérité, axes C/I/D affectés), référence légère
  optionnelle vers un Ticket/Problem GLPI existant, lien optionnel vers une entrée du registre de
  risques et cause racine/enseignements tirés obligatoires à la clôture, pour boucler la clause
  A.5.27 sans dupliquer le système de tickets natif de GLPI
- Plan d'action de traitement des risques (issue #31, clause 8.3/6.1.3 ISO 27001) : suivi des
  actions concrètes (responsable, échéance, statut) mettant réellement en œuvre une décision de
  traitement "mitiger"/"transférer", jusqu'à leur clôture effective

## Version 1.5

- Rapports exportables (PDF, CSV) pour audit externe
- Import/export de la SoA au format standard
- Workflow d'approbation multi-niveaux pour l'acceptation de risque
- API REST publique documentée (OpenAPI)

## Version 2.0

- Cartographie des risques (heatmap probabilité x impact interactive)
- Bibliothèque de contrôles étendue (ISO 27002, NIST CSF, CIS Controls) en complément de
  l'Annexe A ISO 27001
- Intégration avec [glpi-security-incidents](https://github.com/parime/glpi-security-incidents) :
  un incident de sécurité lié à une ou plusieurs CVE pourrait alimenter le registre de risques
  générique (risque cyber accepté/mitigé), sans fusionner les deux plateformes. Remplace le plan
  d'intégration avec glpi-vulnerability-manager (archivé), dont le suivi CVE est désormais couvert
  nativement par glpi-security-incidents

## Suivi

L'avancement réel (issues, PR, jalons) est suivi sur le
[GitHub Project](https://github.com/parime/glpi-iso27001-management) du repository une fois celui-ci
activé. Les priorités peuvent évoluer en fonction des retours communautaires : voir
[GOVERNANCE.md](GOVERNANCE.md) pour le processus de décision.
