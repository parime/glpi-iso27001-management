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

## Version 2.0 (livrée)

- **Absorption complète du plugin jumeau [glpi-security-incidents](https://github.com/parime/glpi-security-incidents)**
  (désormais archivé) : le registre léger d'incidents de sécurité de la v1.0 (issue #29, simple
  fiche de conformité) est remplacé par un véritable objet ITIL — acteurs, workflow, tâches,
  notifications, suivi CVE, modèles d'incident — visible dans le menu Assistance aux côtés de
  Ticket/Problem/Change. Les champs de classification ISO 27001 (catégorie, sévérité, axes C/I/D,
  cause racine/enseignements tirés obligatoires à la clôture, lien vers le registre de risques) et
  le suivi CVE sont fusionnés directement sur ce même objet : un seul enregistrement par incident
  réel, plus de double saisie entre le suivi opérationnel et la conformité. Chaque partie du
  module (base, CVE, modèles, tableau de bord) reste activable/désactivable indépendamment.
  Migration automatique des données existantes à la mise à jour, sans action manuelle. PHP 8.2
  minimum requis (hérité du plugin absorbé).

## Version 2.1 (livrée)

- **Taux de réussite des formations évaluées** : deuxième indicateur manquant identifié lors d'un
  audit complet du plugin (recherche des bonnes pratiques KPI ISO 27001:2022), après le délai de
  réponse aux incidents (v2.0.0). Nouvel axe indépendant du statut de réalisation existant : une
  formation évaluée (quiz, test pratique...) peut être marquée réussie ou échouée, sans affecter
  les formations suivies sans évaluation formelle.

## Version 2.2 (à venir)

- ~~Cartographie des risques (heatmap probabilité x impact interactive)~~ — **livré** : nouvel
  écran (`front/riskheatmap.php`, accessible depuis un bouton sur la liste des risques) affichant
  une grille probabilité x impact, chaque cellule colorée selon le niveau de risque configuré
  (`front/config.php`, la même matrice administrable que le Sprint 2) et affichant le nombre de
  risques qui y tombent. Chaque cellule est cliquable et ouvre la liste des risques filtrée sur
  cette combinaison exacte.
- **Enrichissement CVE via NVD** — **livré** : chaque CVE suivie sur un incident de sécurité peut
  être enrichie automatiquement (score CVSS, sévérité, description, liens de correctif/avis
  éditeur) depuis la base publique NVD (National Vulnerability Database), avec mise en avant
  visuelle des CVE au-delà d'un seuil de score configurable. Désactivé par défaut
  (`front/config.php`), rafraîchi automatiquement chaque jour et manuellement à la demande. Aucune
  donnée inventée : un score ou un correctif absent s'affiche comme explicitement en attente/non
  trouvé, jamais comme une valeur par défaut.
- Bibliothèque de contrôles étendue (ISO 27002, NIST CSF, CIS Controls) en complément de
  l'Annexe A ISO 27001
- **Corrélation des CVE avec le parc GLPI** — **livré** : nouvel écran « Produits (corrélation
  CVE) » où un admin déclare des produits canoniques (éditeur + produit), chacun rattaché à un ou
  plusieurs identifiants CPE (tels que rapportés par le NVD) et à un ou plusieurs alias de nom de
  logiciel (correspondance exacte avec `glpi_softwares.name`, jamais approximative). L'onglet CVE
  d'un incident affiche désormais, pour toute CVE enrichie dont le NVD référence des CPE affectés,
  les actifs du parc potentiellement concernés (calculé à la demande, jamais persisté), avec
  évaluation de version à 3 états (concerné / non vérifiable / exclu par version). Aucune donnée
  inventée : un logiciel installé sans alias déclaré est ignoré plutôt que deviné.

## Suivi

L'avancement réel (issues, PR, jalons) est suivi sur le
[GitHub Project](https://github.com/parime/glpi-iso27001-management) du repository une fois celui-ci
activé. Les priorités peuvent évoluer en fonction des retours communautaires : voir
[GOVERNANCE.md](GOVERNANCE.md) pour le processus de décision.
