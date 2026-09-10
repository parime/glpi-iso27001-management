# Plan de développement

Statut : Sprint 7 terminé. Ce document découpe la Version 1.0 (voir [ROADMAP.md](../../ROADMAP.md))
en itérations livrables, et liste les risques techniques identifiés. Il est mis à jour à chaque
fin de sprint.

## Méthode

Cycle appliqué à chaque sprint : Analyse → Conception → Développement → Tests → Revue de code →
Documentation → Validation → intégration dans `dev`. Aucune étape n'est sautée (voir
[CONTRIBUTING.md](../../CONTRIBUTING.md) et [DEFINITION_OF_DONE.md](../../DEFINITION_OF_DONE.md)).
Développement en petites itérations : jamais l'ensemble du plugin en une fois. Architecture reprise
du plugin jumeau [glpi-vulnerability-manager](https://github.com/parime/glpi-vulnerability-manager)
(même auteur) : `setup.php`/`hook.php` en racine, classes GLPI legacy sous `inc/`, logique
pure-PHP testable sous `src/Services/`, installeur sous `src/Install/`, écrans sous `front/`.

## Découpage Version 1.0

| Sprint | Statut | Objectif | Livrables clés |
|---|---|---|---|
| **1. Infrastructure plugin** | ✅ Terminé | Squelette installable | `setup.php`, `hook.php`, migration initiale (`src/Install/Installer.php`), droit GLPI dédié (`plugin_grcmanager`), vérification version GLPI/PHP, désinstallation propre, premier registre de risques génériques (`PluginGrcmanagerRisk`) fonctionnel de bout en bout (liste avec badges colorés traduits et lien cliquable, formulaire, calcul automatique du niveau de risque via `RiskScoringService`) ; **validé de bout en bout contre GLPI 11 réel** |
| **2. Registre de risques génériques (v2)** | ✅ Terminé | Matrice administrable | Matrice probabilité x impact configurable depuis l'interface GLPI (`front/config.php`, `RiskMatrixConfig`, au lieu de la matrice fixe du Sprint 1, voir `TECH_DEBT.md`), filtres réellement fonctionnels par catégorie/probabilité/impact/niveau/traitement/statut et vue « Mes risques » par propriétaire, rappels de date de revue (tâche Cron `cronReviewreminder()` + notification GLPI native) ; **validé de bout en bout contre GLPI 11 réel** |
| **3. Déclaration d'Applicabilité (SoA)** | ✅ Terminé | Clause 6.1.3 | Les 93 contrôles Annexe A ISO/IEC 27001:2022 réels (`PluginGrcmanagerControl`, seedés à l'installation, 4 thèmes 37/8/14/34), applicabilité (oui/non/partielle) avec justification obligatoire si non pleinement applicable, statut de mise en œuvre, lien many-to-many vers le registre de risques, liste filtrable par thème/applicabilité/statut avec badges traduits, 3 cartes de tableau de bord (contrôles revus, par applicabilité, par état de mise en œuvre) ; **validé de bout en bout contre GLPI 11 réel** |
| **4. Audits internes et CAPA** | ✅ Terminé | Programme d'audit | Programme d'audit interne (`PluginGrcmanagerAudit`, clause 9.2) : auditeur, statut, dates planifiée/réalisée, périmètre libre, catégories de risque et contrôles Annexe A couverts (lien many-to-many). Non-conformités et CAPA (`PluginGrcmanagerNonconformity`, clause 10.2) : sévérité, cause racine, action corrective, action préventive, responsable, échéance, statut, date de vérification de clôture ; validation serveur réelle (action corrective obligatoire pour clôturer/vérifier). Tâche Cron quotidienne pour les CAPA en retard (`cronOverduecapa()`, notification GLPI native). Listes filtrables avec badges colorés traduits, 3 nouvelles cartes de tableau de bord ; **validé de bout en bout contre GLPI 11 réel** |
| **5. Risques fournisseurs/tiers** | ✅ Terminé | Registre dédié | Registre de risques fournisseurs/tiers dédié (`PluginGrcmanagerSupplierRisk`), rattaché au `Supplier` natif de GLPI (`suppliers_id`), avec exactement les mêmes mécanismes d'acceptation/traitement que le registre générique (catégorie/probabilité/impact/niveau calculé/traitement/statut/date de revue), notation partagée via le nouveau trait `GlpiPlugin\Grcmanager\Traits\RiskAssessmentTrait` (utilisé aussi par `PluginGrcmanagerRisk`, refactoré sans changement de comportement) pour que les deux registres ne puissent jamais diverger sur leur calcul de niveau de risque. Même mécanisme de rappel de revue que le Sprint 2 (`ReviewReminderService` généralisée, une tâche Cron dédiée par itemtype). Liste filtrable dont un vrai filtre natif GLPI par Fournisseur lié (jointure `glpi_suppliers`), formulaire dédié, 2 nouvelles cartes de tableau de bord ; **validé de bout en bout contre GLPI 11 réel** |
| **6. Formations et revues de direction** | ✅ Terminé | Suivi organisationnel | Suivi des formations de sensibilisation à la sécurité (`PluginGrcmanagerTraining`, clauses 7.2/7.3) avec suivi individuel de réalisation par participant (statut/date par vrai `User` GLPI lié, renouvellement périodique optionnel), tâche Cron quotidienne de rappel de renouvellement en retard (`cronRenewaldue()`, notification GLPI native) et enregistrement des revues de direction (`PluginGrcmanagerManagementReview`, clause 9.3 : participants, ordre du jour, décisions/actions). Listes filtrables avec badges colorés traduits et lien cliquable, formulaires dédiés, 3 nouvelles cartes de tableau de bord (taux de réalisation des formations, participants en retard de renouvellement, revues de direction par statut) ; **validé de bout en bout contre GLPI 11 réel** |
| **7. Dashboards** | ✅ Terminé | Restitution | Sprint de consolidation (pas de nouvelle fonctionnalité majeure) : les 15 cartes `Hooks::DASHBOARD_CARDS` posées aux Sprints 1 à 6 vérifiées une à une contre une instance GLPI 11 réelle (aucune cassée, aucun besoin de carte supplémentaire identifié), et nouveau tableau de bord natif GLPI seedé à l'installation (`DefaultDashboardService`) reprenant ces 15 cartes pour qu'une installation fraîche affiche d'emblée une vue d'ensemble ISMS ; **validé de bout en bout contre GLPI 11 réel** |
| **8. Documentation et release** | ⏳ À venir | v1.0.0 | Documentation utilisateur/développeur complète, CI/CD verte, changelog, tag signé |

Chaque sprint se termine par une revue par rapport à la
[Definition of Done](../../DEFINITION_OF_DONE.md) avant de passer au suivant. Le contenu détaillé
(tâches, estimation) de chaque sprint est géré via les issues et milestones GitHub, pas dupliqué
dans ce document.

## Environnement de développement et tests

- Instance GLPI 11 réelle (Docker) pour toute validation, jamais une simple relecture de code.
- Niveaux de tests : unitaires (`tests/Unit`, logique pure sous `src/Compatibility/` et
  `src/Services/Risk/`), installation réelle sur GLPI (job CI dédié).
- CI GitHub Actions : syntaxe PHP, PHPStan, PHP_CodeSniffer, PHPUnit, Semgrep, Gitleaks, Trivy,
  installation réelle sur GLPI (Docker) ; voir [.github/workflows/ci.yml](../../.github/workflows/ci.yml).

## Analyse des risques techniques

| Risque | Impact | Probabilité | Mitigation |
|---|---|---|---|
| Périmètre ISO 27001 très large (93 contrôles Annexe A, audits, CAPA, tiers, formations, revues) | Blocage de la v1.0 | Moyenne | Découpage strict en sprints indépendants (voir tableau ci-dessus), chacun livrable et validable seul |
| Chevauchement fonctionnel avec glpi-security-incidents (suivi CVE/incidents) | Confusion utilisateur, double saisie | Faible | Séparation actée dès la conception (historiquement issue #89 du plugin glpi-vulnerability-manager, depuis archivé et remplacé par glpi-security-incidents) : ce plugin ne traite aucune donnée CVE/incident, l'intégration avec glpi-security-incidents (v2.0, voir ROADMAP.md) reste optionnelle |
| Modèle de données figé trop tôt (ex. matrice de risque fixe du Sprint 1) | Refonte coûteuse plus tard | Faible | Documenté explicitement dans `TECH_DEBT.md`, évolution planifiée dès le Sprint 2 |
| Divergence entre modèle GLPI et modèle du plugin (évolutions du core GLPI 11→12) | Rupture de compatibilité | Moyenne | Aucune dépendance à des mécanismes non documentés/non stables de GLPI ; vérification de version au chargement |

## Critères de livraison Version 1.0

Repris de la Definition of Done, complétés par les critères produit :

- Fonctionnel : registre de risques génériques, SoA, audits/CAPA, risques tiers, formations,
  revues de direction, dashboards.
- Technique : installation et désinstallation propres, migrations idempotentes, compatibilité
  GLPI 11.x vérifiée.
- Qualité : tests verts, CI/CD verte, analyse de sécurité sans alerte non justifiée.
- Documentation : README, guide utilisateur, guide administrateur, guide développeur.
- Open Source : licence GPLv3, repository propre, release publiée avec changelog.
