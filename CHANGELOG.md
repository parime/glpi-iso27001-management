# Changelog

Toutes les évolutions notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et ce projet adhère au
[Semantic Versioning](https://semver.org/lang/fr/) (`MAJEUR.MINEUR.CORRECTIF`).

## [Non publié]

### Changed

- **Dépôt renommé** de `glpi-grc-manager` vers `glpi-iso27001-management` — nom jugé plus parlant
  (le plugin est un ISMS ISO 27001 : risques, contrôles/SoA, audits, non-conformités). GitHub
  redirige automatiquement l'ancien nom. Le namespace PHP (`GlpiPlugin\Grcmanager`), les noms de
  table (`glpi_plugin_grcmanager_*`) et la clé du plugin restent inchangés — casser ça imposerait
  une migration de données à toute installation existante pour un simple changement de nom de
  dépôt.

### Added

- Test unitaire pour `plugin_grcmanager_redefine_menus()` (`setup.php`), le hook qui corrige le
  doublon de libellé du sous-menu "GRC & Conformité" (v1.1.4) — c'est une fonction pure (aucune
  dépendance GLPI dans son propre corps), testable directement sans instance GLPI réelle, qui
  n'avait pourtant aucun test malgré ça. Ne couvre que la logique de transformation du tableau de
  menu elle-même ; l'effet réel sur le rendu GLPI (breadcrumb, absence de doublon visuel) reste
  hors de portée d'un test unitaire et nécessiterait un test au niveau navigateur/GLPI réel.

## [1.1.4] - 2026-09-04

### Fixed

- **Le sous-menu "GRC & Conformité" répétait deux fois le même libellé** (retour utilisateur direct
  sur la v1.1.3) : `PluginGrcmanagerMenu`, l'ancre qui porte le titre/icône du sector (voir son
  propre docblock), restait aussi visible comme premier élément cliquable du sous-menu qu'elle
  ouvre - GLPI core (`Html::generateMenuSession()`) ajoute systématiquement chaque classe de
  `MENU_TOADD` comme une ligne de sous-menu, sans distinction entre "porte le titre du sector" et
  "écran réel". Corrigé en ajoutant un hook `Hooks::REDEFINE_MENUS` (`plugin_grcmanager_redefine_menus()`
  dans `setup.php`) : GLPI core l'appelle juste après avoir construit le menu complet, ce qui permet
  de retirer la ligne de l'ancre du sous-menu une fois que le titre/icône du sector sont déjà
  fixés, sans toucher à l'ordre ni aux 11 écrans réels.

## [1.1.3] - 2026-09-03

### Fixed

- **Fil d'Ariane des 11 écrans du plugin toujours "Outils" après le nouveau menu "GRC &
  Conformité" (v1.1.2)** : chaque contrôleur `front/*.php`/`front/*.form.php` appelait
  `Html::header(..., 'tools', ...)` avec le sector natif codé en dur, jamais mis à jour lors du
  déplacement de ces classes vers le nouveau sector `'grcmanager'` dans `setup.php`. GLPI core
  (`Html::header()`, `page_header.html.twig`) construit le fil d'Ariane et met en évidence la
  section active de la barre latérale directement à partir de ce paramètre `$sector`, indépendamment
  du sector réellement enregistré dans `MENU_TOADD` : la navigation elle-même fonctionnait (bonne
  URL, bon contenu), mais la barre latérale revenait visuellement sur "Outils" à chaque clic sur un
  sous-menu, donnant l'impression trompeuse d'être renvoyé au mauvais endroit. Les 21 appels
  concernés (tous sauf `front/config.php`, hors périmètre du nouveau menu) pointent désormais vers
  `'grcmanager'`.
- **Le premier élément du sous-menu "GRC & Conformité" (l'ancre `PluginGrcmanagerMenu`) renvoyait à
  l'accueil au lieu du tableau de bord ISMS.** Son lien utilisait
  `/front/central.php?dashboard=...` sans le paramètre `embed` : `Glpi\Controller\CentralController`
  (GLPI core) n'honore `dashboard` que combiné à `embed` (rendu anonyme sans menu/en-tête) - sans
  lui, le paramètre est silencieusement ignoré et la page affiche le dernier tableau de bord
  "Central" utilisé. Corrigé en pointant vers le registre des risques
  (`PluginGrcmanagerRisk::getSearchURL()`), un vrai écran du plugin plutôt qu'un lien qui n'aboutit
  jamais où il annonce mener.

## [1.1.2] - 2026-09-03

### Changed

- **Menu de premier niveau dédié "GRC & Conformité"** : les 11 écrans du plugin (Risques, Risques
  fournisseurs, Contrôles Annexe A, Obligations légales/réglementaires/contractuelles, Audits
  internes, Non-conformités, Incidents de sécurité, Formations, Revues de direction, Politiques de
  sécurité, Objectifs ISMS) étaient jusqu'ici tous enregistrés à plat dans le menu natif GLPI
  "Outils", devenu encombré. Ils apparaissent maintenant sous leur propre entrée de menu de premier
  niveau "GRC & Conformité" (icône bouclier), au même niveau que Parc/Assistance/Gestion/Outils/
  Administration/Configuration. Nouvelle classe ancre `PluginGrcmanagerMenu`
  (`inc/menu.class.php`), sans table ni donnée propre, dont le seul rôle est de porter le titre et
  l'icône de ce nouveau sector (voir son docblock pour le mécanisme GLPI core exact) ; son propre
  lien de menu pointe vers le tableau de bord ISMS déjà existant. Ce changement concerne uniquement
  la navigation : la fiche Profil (Administration > Profils) n'est pas affectée, ce plugin
  n'exposant qu'un droit unique et opaque (`plugin_grcmanager`), jamais rendu dans la matrice de
  droits de GLPI.

## [1.1.1] - 2026-09-03

### Fixed

- **Plan de traitement d'un risque : erreur fatale en laissant l'échéance vide** (issue #31) :
  `PluginGrcmanagerRiskTreatmentAction::validateAndNormalize()` ne normalisait pas `due_date` avant
  l'insertion. Le champ `<input type="date">` du mini-formulaire d'ajout d'action
  (`PluginGrcmanagerRisk::showTreatmentPlan()`) soumet une chaîne vide `''` (et non une absence de
  champ) quand aucune date n'est saisie ; `due_date` étant une colonne `DATE` nullable, cette chaîne
  vide atteignait MySQL telle quelle et le mode strict la rejetait ("Incorrect date value: '' for
  column `due_date`"), provoquant une page d'erreur fatale au lieu d'enregistrer l'action sans
  échéance. `''` est maintenant convertie en `null` avant l'ajout/la mise à jour.

## [1.1.0] - 2026-09-03

### Added

- **Registre des incidents de sécurité de l'information** (issue #29, ISO/IEC 27001:2022 Annexe A
  A.5.24-27) : les contrôles A.5.24-27 (planification/réponse/apprentissage des incidents)
  n'existaient jusqu'ici que comme lignes à cocher dans la SoA - GLPI dispose nativement de
  Ticket/Problem, mais rien ne les qualifiait comme "incident de sécurité de l'information" au sens
  ISO ni ne les reliait au registre de risques de ce plugin. Nouveau registre indépendant
  (`PluginGrcmanagerSecurityIncident`, table `glpi_plugin_grcmanager_securityincidents`) : intitulé,
  date de l'incident, catégorie (violation de données/logiciel malveillant/accès non autorisé/
  indisponibilité/autre), sévérité (mineure/majeure/critique, même échelle que
  `PluginGrcmanagerNonconformity`), axes confidentialité/intégrité/disponibilité affectés (cases à
  cocher, liste séparée par des virgules en base, même convention que `PluginGrcmanagerAudit.
  risk_categories`), statut (ouvert/en investigation/contenu/clôturé), cause racine et enseignements
  tirés. Référence légère optionnelle vers un Ticket ou Problem GLPI déjà existant
  (`linked_itemtype`/`linked_items_id`, lien construit via `CommonDBTM::getLinkURL()` natif, jamais
  une duplication du contenu du ticket), et lien optionnel zéro-ou-un vers une entrée du registre de
  risques (`plugin_grcmanager_risks_id`, même convention que le lien obligation <-> risque de
  l'issue #30) pour boucler la clause A.5.27 ("tirer des enseignements des incidents"). La cause
  racine et les enseignements tirés ne sont obligatoires qu'à la clôture de l'incident, jamais pour
  l'ouvrir ou le mettre en investigation (même convention "obligatoire seulement à la clôture" que
  l'action corrective de `PluginGrcmanagerNonconformity`, issue #27). Nouvel écran sous Outils
  (liste filtrable, formulaire), 2 nouvelles cartes de tableau de bord (incidents par statut, par
  sévérité) ajoutées au tableau de bord ISMS seedé à l'installation. Logique pure (normalisation
  catégorie/sévérité/statut, liste C/I/D, liens optionnels, validation de clôture) extraite dans
  `GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentRules`, testée unitairement. Réutilise le
  droit plat existant `plugin_grcmanager`, aucune preuve d'un besoin de droit dédié par
  fonctionnalité dans ce plugin.
- **Bibliothèque de politiques de sécurité versionnées** (issue #28, ISO/IEC 27001:2022
  A.5.1/A.5.1.1/A.5.1.2) : le contrôle A.5.1 de la SoA n'existait jusqu'ici que comme ligne à
  cocher, sans aucun outil derrière. Nouveau registre natif (`PluginGrcmanagerPolicy`, table
  `glpi_plugin_grcmanager_policies`) pour gérer les politiques de sécurité (charte informatique,
  politique de mots de passe, PCA...) avec un vrai cycle de vie : statut brouillon/approuvée/
  archivée, version libre (ex. « 1.2 »), date d'approbation (obligatoire pour passer au statut
  approuvée, validation serveur réelle), prochaine date de revue et propriétaire. Le ou les
  documents réels (PDF, Word...) sont attachés via le mécanisme natif GLPI Document/Document_Item
  (onglet « Documents » standard, `PluginGrcmanagerPolicy::defineTabs()` +
  `$CFG_GLPI['document_types']`), jamais un système de stockage propre à ce plugin. Rappels de
  revue automatiques sur `next_review_date` (tâche Cron quotidienne
  `PluginGrcmanagerPolicy::cronReviewreminder()`, notification GLPI native), même mécanisme que les
  rappels de revue déjà en place sur le registre de risques (`ReviewReminderService`), avec sa
  propre fenêtre de 30 jours extraite en logique pure testable (`PolicyReviewReminderWindow`).
  Nouvel écran sous Outils, liste filtrable par statut avec badge coloré, 2 nouvelles cartes de
  tableau de bord (« Politiques en attente de revue », « Politiques de sécurité par statut »),
  ajoutées au tableau de bord ISMS seedé à l'installation. Aucun lien direct posé entre la ligne
  A.5.1 de la SoA et ce registre (voir TECH_DEBT.md pour le raisonnement).

- **Registre des obligations légales, réglementaires et contractuelles** (issue #30, ISO 27001
  clause 4.2 « parties intéressées et leurs exigences », Annexe A A.5.31-36) : les contrôles
  A.5.31-36 n'existaient jusqu'ici que comme lignes à cocher dans la Déclaration d'Applicabilité
  (`PluginGrcmanagerControl`) - nouveau registre indépendant (`PluginGrcmanagerComplianceObligation`,
  table `glpi_plugin_grcmanager_complianceobligations`) pour centraliser ce qui, en pratique, finit
  souvent en tableur Excel isolé : intitulé, type (légale/réglementaire/contractuelle), référence/
  source (ex. « RGPD », « Contrat client Acme SA »), statut de conformité (conforme/partiellement
  conforme/non conforme/non évaluée), propriétaire, date de revue et description/notes. Lien
  optionnel zéro-ou-un vers une entrée du registre de risques quand le non-respect de l'obligation
  constitue un risque identifié (`plugin_grcmanager_risks_id`, colonne directe - pas une table de
  liaison many-to-many, cardinalité plus simple que le lien risque <-> actifs CMDB de l'issue #25).
  Nouvel écran sous Outils (liste filtrable, formulaire), 4 nouvelles cartes de tableau de bord
  (obligations non conformes, en attente de revue, par type, par statut de conformité) ajoutées au
  tableau de bord ISMS seedé du Sprint 7, et même mécanisme de rappel de revue (Cron quotidien +
  notification GLPI native) que le registre de risques - `ReviewReminderService` généralisé pour
  accepter un itemtype sans colonne `status` (l'obligation n'a pas d'état "clôturé", seulement un
  statut de conformité, qui ne dispense jamais de la revue périodique). Logique pure (normalisation
  type/statut, lien zéro-ou-un, fenêtre de rappel de revue) extraite dans
  `GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules`, testée unitairement.
- **Objectifs ISMS et suivi de KPI dans le temps** (issue #32, ISO 27001 clause 6.2) : le tableau
  de bord ISMS (15 cartes) montrait l'état actuel du système de management, une photo instantanée,
  mais rien ne permettait de fixer des objectifs de sécurité mesurables (ex. « réduire de 20% les
  non-conformités récurrentes d'ici fin d'année ») ni de suivre leur progression dans le temps —
  une trajectoire. Nouvelle classe `PluginGrcmanagerObjective` (table
  `glpi_plugin_grcmanager_objectives`) : titre, description, propriétaire (vrai `User` GLPI),
  échéance, statut (non démarré/sur la bonne voie/à risque/atteint/manqué), et une cible en DEUX
  champs indépendants tous deux optionnels — `target_value` (numérique) pour un objectif chiffré et
  `target_description` (texte libre) pour un objectif purement qualitatif ("obtenir la
  certification ISO 27001") — plutôt que de forcer chaque objectif dans une case numérique
  artificielle. Nouvelle classe `PluginGrcmanagerObjectiveMeasurement` (table
  `glpi_plugin_grcmanager_objectivemeasurements`) : historique manuel de mesures dans le temps
  ("à cette date, nous en sommes à X"), volontairement PAS auto-calculé depuis d'autres données du
  plugin pour cette première version (même philosophie "version minimale et testée" que le reste de
  ce plugin, voir `TECH_DEBT.md`) ; une mesure sur un objectif chiffré exige une valeur numérique,
  une mesure sur un objectif purement qualitatif accepte une valeur vide mais exige alors un
  commentaire (`GlpiPlugin\Grcmanager\Services\Objective\ObjectiveMeasurementValidator`, testé
  unitairement). Nouvel écran sous Outils (liste + formulaire), avec sur la fiche de chaque
  objectif un mini-formulaire d'ajout de mesure et l'historique chronologique complet en simple
  tableau (pas de graphique : une trajectoire honnête sans nouvelle dépendance de rendu). Lien léger
  many-to-many vers les revues de direction (`PluginGrcmanagerManagementReview`, nouveau champ
  "Objectifs ISMS abordés", même convention de lien direct `$DB` que le lien contrôle <-> risque) :
  la clause 9.3 ISO 27001 liste explicitement "l'étendue de l'atteinte des objectifs de sécurité de
  l'information" parmi les données d'entrée attendues d'une revue de direction. Nouvelle carte de
  tableau de bord "Objectifs ISMS par statut" (`DashboardCardService::objectivesByStatus()`),
  reprise dans le tableau de bord seedé à l'installation (`DefaultDashboardService`, 16e carte).
  Réutilise le droit plat existant `plugin_grcmanager`, aucune preuve d'un besoin de droit dédié par
  fonctionnalité dans ce plugin.
- **Plan d'action de traitement des risques** (issue #31, ISO 27001 clause 8.3/6.1.3) : un risque
  "à mitiger"/"à transférer" n'avait jusqu'ici que sa décision (`treatment`) et une justification en
  texte libre, rien ne suivait les actions concrètes menant réellement à sa clôture, contrairement
  au CAPA structuré des non-conformités. Nouvelle table enfant one-to-many
  `PluginGrcmanagerRiskTreatmentAction` (`glpi_plugin_grcmanager_risktreatmentactions`, un risque
  peut avoir zéro, une ou plusieurs actions, chacune avec sa propre description, son responsable
  (vrai `User` GLPI), son échéance et son statut planifiée/en cours/réalisée) plutôt qu'un simple
  ensemble de champs plats façon CAPA d'une non-conformité : contrairement à une non-conformité (une
  action corrective ET une action préventive, fixes), un plan de traitement de risque compte
  naturellement plusieurs actions indépendantes ("corriger le système" ET "ajouter une supervision"
  ET "former les équipes"), chacune suivie jusqu'à sa propre clôture - voir le docblock de
  `PluginGrcmanagerRiskTreatmentAction` pour le raisonnement complet et l'alternative "champs plats"
  écartée. Uniquement pertinent quand `treatment` vaut "mitiger" ou "transférer"
  (`TreatmentPlanRules::isTreatmentPlanRelevant()`, testée unitairement) : "accepter" n'a par
  définition aucun plan de traitement, "éviter" élimine la source du risque sans action à suivre
  dans la durée. Mini-formulaire d'ajout et liste des actions (mise à jour de statut, suppression)
  affichés directement sur la fiche du risque concerné (`PluginGrcmanagerRisk::showTreatmentPlan()`,
  hors du `<form>` principal comme `PluginGrcmanagerObjective::showMeasurementHistory()`, voir la
  Pull Request pour le détail de cette contrainte HTML). Un risque à mitiger/transférer ne peut plus
  être clôturé sans au moins une action de traitement enregistrée (validation serveur réelle,
  message d'erreur, même mécanisme que l'action corrective obligatoire à la clôture d'une
  non-conformité). "En retard" reste une condition dérivée (échéance dépassée et statut différent de
  "réalisée"), jamais un statut choisi dans un menu déroulant, même convention que le CAPA en retard,
  les revues de risque, le renouvellement de formation et la revue de politique. Tâche Cron
  quotidienne dédiée (`cronOverduetreatmentaction()`, notification GLPI native vers le responsable
  de l'action) et 2 nouvelles cartes de tableau de bord ("Actions de traitement de risque en
  retard", "Risques à mitiger/transférer sans plan de traitement"), ajoutées au tableau de bord ISMS
  seedé à l'installation.
- **Classification Confidentialité/Intégrité/Disponibilité (C/I/D) des actifs** (issue #26,
  ISO/IEC 27001:2022 A.5.9/A.5.12/A.8.2) : nouveau registre natif, indépendant du lien registre de
  risques <-> actifs de l'issue #25 (`PluginGrcmanagerAssetClassification`, table
  `glpi_plugin_grcmanager_assetclassifications`, clé composite unique itemtype/items_id) — une
  classification est une propriété de l'actif lui-même (ex. « base RH = confidentialité élevée »),
  pas d'un risque particulier qui le mentionne. Réutilise exactement la même liste d'itemtypes
  classifiables que l'issue #25 (`LinkableItemtypes`/`PluginGrcmanagerRisk::getLinkableItemtypes()`),
  jamais une seconde liste divergente. Nouvel onglet "Classification C/I/D" en lecture + édition sur
  la fiche de chaque actif liable (même mécanisme `Plugin::registerClass()`/`addtabon` que l'onglet
  "Risques" de l'issue #25), formulaire à 3 menus déroulants Faible/Moyen/Élevé par axe, chaque axe
  pouvant être renseigné indépendamment (classification partielle valide, pas de tout-ou-rien). Le
  formulaire du registre de risques (`PluginGrcmanagerRisk::showForm()`) affiche désormais, en
  lecture seule, la classification existante de chaque actif déjà lié directement dans le libellé du
  multi-select, ainsi qu'une suggestion non-bloquante à côté du champ Impact quand au moins un actif
  lié porte une classification élevée (n'altère jamais la valeur choisie par l'utilisateur). Décision
  volontaire de ne PAS dépendre du plugin communautaire "Fields" recommandé dans l'issue d'origine :
  une implémentation native est testable et ne suppose pas la présence d'un plugin tiers sur une
  instance donnée (le plugin "Fields" reste mentionné en note d'interopérabilité future dans la PR).

- **Distinction non-conformité / observation-remarque dans les audits** (issue #27, vocabulaire ISO
  19011) : nouveau champ `finding_type` sur `PluginGrcmanagerNonconformity`, indépendant de
  `severity`. Une observation/remarque suit le même workflow CAPA qu'une non-conformité mais sans
  action corrective/préventive obligatoire pour être clôturée/vérifiée (elle peut toujours en
  recevoir une volontairement). Liste filtrable par type de constat avec badge coloré, formulaire
  mis à jour, migration idempotente (`Migration::addField()`) avec valeur par défaut
  `nonconformity` pour ne reclasser aucun constat d'audit existant. La carte de tableau de bord
  « Non-conformités ouvertes » ne compte désormais plus que les vraies non-conformités. Résout la
  dette documentée dans TECH_DEBT.md Sprint 4.
- **Lien registre de risques <-> actifs GLPI/CMDB** (issue #25) : un risque peut désormais être
  lié à zéro, un ou plusieurs actifs réels de la CMDB (Computer, Monitor, NetworkEquipment,
  Peripheral, Phone, Printer, Software, ainsi que tout actif personnalisé actif) via une nouvelle
  table de liaison polymorphe `glpi_plugin_grcmanager_risks_items` (itemtype/items_id, sur le même
  modèle que les tables de liaison polymorphes du cœur GLPI, ex. `glpi_documents_items`) —
  volontairement PAS une colonne `itemtype`/`items_id` directe sur `glpi_plugin_grcmanager_risks`
  elle-même, qui aurait imposé à tort une cardinalité 1-vers-1. Un multi-select par itemtype liable
  dans le formulaire du risque (`PluginGrcmanagerRisk::showForm()`), et un nouvel onglet "Risques"
  en lecture seule sur la fiche de chaque actif liable (`getTabNameForItem()`/
  `displayTabContentForItem()`, `Plugin::registerClass()`/`addtabon` dans `setup.php`) pour
  répondre au besoin inverse ("quels risques pèsent sur cet actif ?"). Gérée par de simples
  méthodes statiques en accès direct `$DB` (`getLinkedAssets()`/`syncLinkedAssets()`/
  `getRisksLinkedToItem()`), pas une vraie classe `CommonDBRelation`, même simplification déjà
  assumée pour tous les autres liens de ce plugin depuis le Sprint 3 (voir TECH_DEBT.md).

## [1.0.1] - 2026-08-26

### Added

- **Fiche marketplace** (`grcmanager.xml`) et logo : le plugin n'avait encore ni catalogue
  marketplace ni aucun visuel alors que la v1.0.0 est stable et publiée. Ajout de la fiche
  complète (description, tags, captures d'écran), d'un logo placeholder (thème ISO 27001) et
  d'une automatisation dans `release.yml` qui ouvre désormais une PR de mise à jour du catalogue
  à chaque release, pour éviter la dérive silencieuse déjà rencontrée sur les plugins jumeaux
  (assetsign-glpi#105).

### Fixed

- Badges README (statut "alpha — sprint 7") restés obsolètes depuis le passage en v1.0.0 stable.

## [1.0.0] - 2026-08-25

Les 8 sprints du plan de développement sont terminés. Testé en charge à l'échelle d'un ISMS
réel sur une instance secondaire (350 risques, 93 contrôles SoA, 40 audits, 80 non-conformités,
60 risques fournisseurs, 30 formations) : toutes les listes chargent en 40 à 200ms, toutes les
requêtes des cartes de tableau de bord en moins d'une milliseconde côté base. CI/CD complète
verte, pipeline de release relu de bout en bout. Recommandation non bloquante documentée dans
TECH_DEBT.md : le tutoriel (27 captures d'écran) ne couvre que les Sprints 1 à 4, à compléter
pour les Sprints 5 à 7 (risques fournisseurs, formations, revues de direction, tableau de bord
par défaut, suivi de version GitHub).

### Fixed

- **`README.md`/`README.en.md` affichaient encore un statut "Sprint 1 terminé"** alors que les
  Sprints 1 à 7 sont terminés (badge de statut et section "État du projet"/"Project status" mis à
  jour en conséquence). Constat fait lors de la revue de préparation v1.0.0 (Sprint 8).
- **`plugin.json` : champ `note` obsolète**, encore rédigé au passé du Sprint 6 alors que le
  Sprint 7 (tableaux de bord, consolidation) est terminé depuis. Mis à jour pour refléter l'état
  réel du projet, sans toucher aux champs `version`/`state`/`screenshots` (réservés à la
  publication de la v1.0.0).

### Added

- **Sprint 7 (tableaux de bord, consolidation)** : les 15 cartes posées aux Sprints 1 à 6 ont été
  vérifiées une à une contre une instance GLPI 11 réelle avec de vraies données (schéma, valeurs
  d'énumération et sortie de chaque `DashboardCardService::*` comparés directement) : aucune
  n'était cassée, aucune carte redondante ou manquante identifiée. Nouveau tableau de bord natif
  GLPI seedé à l'installation (`GlpiPlugin\Grcmanager\Services\Dashboard\DefaultDashboardService`),
  reprenant les 15 cartes déjà posées, pour qu'une installation fraîche affiche d'emblée une vue
  d'ensemble ISMS plutôt qu'un sélecteur « Ajouter une carte » vide. Visible par tout utilisateur
  disposant du droit natif « dashboard », exactement comme les tableaux de bord natifs Central/
  Parc/Assistance ; retiré proprement à la désinstallation.
- **Suivi de la dernière version publiée sur GitHub** sur l'écran Configuration, à côté de la
  version installée (`GlpiPlugin\Grcmanager\Services\GithubVersionChecker`, mise en cache 24h) :
  même mécanisme que les plugins jumeaux glpi-vulnerability-manager, assetsign-glpi et
  Configuration-glpi-auto.
- Lien vers le tutoriel utilisateur bilingue (`docs/TUTORIAL.md`) ajouté dans la section
  Documentation de `README.md`/`README.en.md`, jusque-là non référencé depuis la page d'accueil du
  dépôt.

### Fixed

- **Colonne Titre non cliquable sur les listes Risques, Risques fournisseurs, Audits, Non-
  conformités, Formations et Revues de direction** : seule la colonne ID (petite cible) ouvrait
  la fiche, le titre affiché s'affichait en texte brut. Constat direct du porteur du plugin sur un
  écran comparable. Colonne Titre passée en `datatype => 'itemlink'` (`itemtype => self::class`)
  sur les 6 classes concernées, vérifié en conditions réelles (HTML rendu avec un vrai lien vers
  la fiche).

### Added

- **Suivi des formations de sensibilisation à la sécurité (clauses 7.2 "compétence" et 7.3
  "sensibilisation" ISO/IEC 27001:2022)** : nouvel itemtype `PluginGrcmanagerTraining`, une ligne
  par session/campagne de formation (titre, format présentiel/e-learning/autre, public cible en
  texte libre, date de réalisation, caractère obligatoire, période de renouvellement optionnelle en
  mois). Suivi individuel de réalisation par participant (vrai `User` natif de GLPI, jamais un
  concept propre à ce plugin) sur la table de liaison
  `glpi_plugin_grcmanager_trainings_users` : statut (en attente/terminée/dispensé) et date de
  réalisation par participant, pas seulement un décompte agrégé. Nouvelle tâche Cron quotidienne
  (`PluginGrcmanagerTraining::cronRenewaldue()`) qui notifie individuellement chaque participant en
  retard de renouvellement (`GlpiPlugin\Grcmanager\Services\Training\TrainingRenewalService`,
  `inc/notificationtargettraining.class.php`, résolution explicite de plusieurs destinataires par
  formation, pas un simple propriétaire unique). Liste filtrable avec badges colorés traduits et
  lien cliquable (`front/training.php`), formulaire dédié (`front/training.form.php`).
- **Enregistrement des revues de direction (clause 9.3 ISO/IEC 27001:2022)** : nouvel itemtype
  `PluginGrcmanagerManagementReview` (titre, statut planifiée/terminée avec auto-renseignement de la
  date de revue dès le passage au statut "Terminée", participants liés via
  `glpi_plugin_grcmanager_managementreviews_users`, ordre du jour et décisions/actions en texte
  libre, volontairement non rattachées au mécanisme CAPA existant, voir `TECH_DEBT.md` Sprint 6).
  Liste filtrable avec badges colorés traduits et lien cliquable
  (`front/managementreview.php`), formulaire dédié (`front/managementreview.form.php`).
- **3 nouvelles cartes de tableau de bord** (`DashboardCardService`, même signature
  accumulateur-safe que les cartes précédentes) : taux de réalisation des formations, participants
  en retard de renouvellement de formation, revues de direction par statut.
- Nouvelles chaînes traduites dans `locales/fr_FR.po`/`locales/en_GB.po`.

### Fixed

- Fil d'Ariane incorrect sur tous les écrans du plugin : `Html::header()` déclarait la catégorie
  `'admin'` (Administration) sur 11 fichiers alors que le plugin est enregistré sous `'tools'`
  (Outils) dans `Hooks::MENU_TOADD`, même bug que sur `glpi-vulnerability-manager`, confirmé en
  direct sur les deux dépôts. Corrigé partout (y compris les 4 nouveaux écrans du Sprint 6 ci-dessus,
  qui n'existaient pas encore lors du premier correctif) pour que le fil d'Ariane et le menu actif
  reflètent l'emplacement réel.

## [0.5.0] - 2026-08-24

### Added

- **Risques fournisseurs/tiers** : nouveau registre dédié, `PluginGrcmanagerSupplierRisk`, avec
  exactement les mêmes mécanismes d'acceptation/traitement que le registre générique
  (`PluginGrcmanagerRisk`) : catégorie/probabilité/impact/niveau de risque calculé, traitement
  (accepter/mitiger/transférer/éviter), propriétaire, justification, date de revue, statut. Chaque
  risque fournisseur est rattaché à un vrai `Supplier` natif de GLPI (`suppliers_id`, jamais un
  concept fournisseur propre à ce plugin), rattachement obligatoire (validation serveur réelle,
  vérifiée en direct). Le calcul de notation (`computed_score`/`risk_level` à partir de
  `probability`/`impact` via la matrice administrable existante) est désormais partagé avec le
  registre générique via un nouveau trait `GlpiPlugin\Grcmanager\Traits\RiskAssessmentTrait`
  (`src/Traits/RiskAssessmentTrait.php`), qui regroupe aussi les énumérations et le rendu des
  badges colorés traduits, pour que les deux registres ne puissent jamais diverger sur leur
  notation ; `PluginGrcmanagerRisk` a été refactoré pour utiliser ce même trait, sans changement de
  comportement. Même mécanisme de rappel de revue que le Sprint 2
  (`GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService`, généralisée pour piloter les deux
  tâches Cron `PluginGrcmanagerRisk::cronReviewreminder()` et
  `PluginGrcmanagerSupplierRisk::cronReviewreminder()` à partir de la même implémentation), nouvelle
  notification GLPI native dédiée (`inc/notificationtargetsupplierrisk.class.php`). Liste filtrable
  par catégorie/probabilité/impact/niveau/traitement/statut et par le Fournisseur lié (vrai filtre
  GLPI natif, jointure réelle sur `glpi_suppliers`), badges colorés traduits, lien cliquable, vue
  « Mes risques fournisseurs » (`front/supplierrisk.php`), formulaire dédié
  (`front/supplierrisk.form.php`). 2 nouvelles cartes de tableau de bord : risques fournisseurs par
  niveau, nombre de fournisseurs ayant au moins un risque élevé/critique encore ouvert
  (`DashboardCardService`, même signature accumulateur-safe que les cartes précédentes). Nouvelles
  chaînes traduites dans `locales/fr_FR.po`/`locales/en_GB.po`.
- **Audits internes et CAPA (clauses 9.2 et 10.2 ISO/IEC 27001:2022)** : deux nouveaux itemtypes.
  `PluginGrcmanagerAudit` porte le programme d'audit interne (titre, périmètre libre, catégories de
  risque couvertes, contrôles Annexe A couverts via un lien many-to-many
  `glpi_plugin_grcmanager_audits_controls`, auditeur, statut planifié/en cours/terminé/annulé, date
  planifiée/réalisée avec auto-renseignement de la date réalisée dès le passage au statut
  « Terminé », conclusion). `PluginGrcmanagerNonconformity` porte le cycle constat -> action
  corrective/préventive -> clôture que la clause 10.2 impose : rattachement à un audit, sévérité
  (mineure/majeure/critique), cause racine, action corrective, action préventive, responsable,
  échéance, statut ouverte/en traitement/clôturée/vérifiée, date de vérification de clôture
  (auto-renseignée à la date du jour dès le passage au statut « Vérifiée »). Validation serveur
  réelle : impossible de clôturer ou vérifier une non-conformité sans action corrective renseignée
  (vérifiée en direct). Nouvelle tâche Cron quotidienne
  (`PluginGrcmanagerNonconformity::cronOverduecapa()`) qui notifie le responsable de chaque action
  corrective/préventive dont l'échéance est dépassée et qui n'est ni clôturée ni vérifiée
  (`OverdueCapaService`, `inc/notificationtargetnonconformity.class.php`), même mécanisme de
  notification GLPI native que les rappels de revue de risque du Sprint 2. Listes filtrables avec
  badges colorés traduits et lien cliquable (`front/audit.php`, `front/nonconformity.php`),
  formulaires dédiés, vue « Mes actions correctives/préventives ». 3 nouvelles cartes de tableau de
  bord : non-conformités ouvertes, actions correctives/préventives en retard, audits internes par
  statut (`DashboardCardService`, même signature accumulateur-safe que les cartes précédentes).
  Nouvelles chaînes traduites dans `locales/fr_FR.po`/`locales/en_GB.po`.
- **Déclaration d'applicabilité (SoA, clause 6.1.3 ISO/IEC 27001:2022)** : nouvel itemtype
  `PluginGrcmanagerControl` portant les 93 mesures réelles de l'Annexe A (37 organisationnelles,
  8 humaines, 14 physiques, 34 technologiques), seedées de façon idempotente à l'installation
  (`Installer::seedControls()`, comptées en direct sur une instance GLPI 11 réelle : 93/93). Pour
  chaque mesure : applicabilité (applicable/non applicable/partiellement applicable), justification
  obligatoire dès que la mesure n'est pas pleinement applicable (validation serveur réelle,
  vérifiée en direct), statut de mise en œuvre (non démarré/en cours/mis en œuvre/vérifié), lien
  many-to-many vers le registre de risques (`glpi_plugin_grcmanager_controls_risks`). Liste
  filtrable par thème/applicabilité/statut avec badges colorés traduits et lien cliquable
  (`front/control.php`), formulaire dédié (`front/control.form.php`). 3 nouvelles cartes de
  tableau de bord : contrôles SoA revus, par applicabilité, par état de mise en œuvre
  (`DashboardCardService`, même signature accumulateur-safe que les cartes du registre de
  risques). 93 nouvelles chaînes traduites (thèmes, statuts, et l'intitulé officiel de chacune des
  93 mesures) dans `locales/fr_FR.po`/`locales/en_GB.po`.
- **Matrice de risque administrable** (`front/config.php`, onglet unique pour l'instant) : la
  grille probabilité x impact utilisée par `RiskScoringService` pour calculer `risk_level` n'est
  plus codée en dur, elle est éditable depuis l'interface GLPI (5x4 menus déroulants colorés) et
  stockée dans `glpi_plugin_grcmanager_riskmatrixconfig`, sur le modèle du plugin jumeau
  glpi-vulnerability-manager. Les valeurs par défaut reproduisent exactement la matrice fixe du
  Sprint 1 : aucun changement pour les installations existantes tant qu'un administrateur ne
  modifie pas la grille (voir `RiskMatrixDefaults`).
- **Filtres de liste réellement fonctionnels** sur catégorie, probabilité, impact, niveau de
  risque, traitement et statut (`PluginGrcmanagerRisk::getSpecificValueToSelect()`) : le Sprint 1
  affichait des badges traduits mais ne permettait de filtrer qu'en tapant la valeur brute non
  traduite dans une case de texte ; ces six colonnes affichent maintenant un vrai menu déroulant
  traduit dans le formulaire de recherche.
- **Vue « Mes risques »** sur la liste (`front/risk.php`) : lien direct vers les risques dont
  l'utilisateur connecté est propriétaire.
- **Rappels de date de revue** : nouvelle tâche Cron GLPI (`PluginGrcmanagerRisk::cronReviewreminder()`,
  quotidienne) qui identifie les risques dont la date de revue est dépassée ou approche (30 jours)
  et déclenche une notification GLPI native (`review_due`) au propriétaire du risque
  (`ReviewReminderService`, `inc/notificationtargetrisk.class.php`), en plus de la carte de
  tableau de bord « Risques en attente de revue » déjà livrée au Sprint 1.

### Fixed

- `front/risk.php` transmettait un tableau de paramètres vide à `Search::showList()`, ignorant
  silencieusement tout critère de recherche présent dans l'URL (tri, pagination, filtres), corrigé
  en fusionnant `$_GET` via `Glpi\Search\Input\QueryBuilder::manageParams()`, le même mécanisme que
  `Search::show()` utilise pour les écrans natifs de GLPI.

## [0.1.0] - 2026-08-24

### Added

- **Squelette de plugin GLPI 11 installable** : `setup.php` (version, compatibilité GLPI 11.0.0 à
  11.99.99, PHP 8.1 minimum), `hook.php`, droit GLPI dédié `plugin_grcmanager` (attribué en full
  au profil Super-Admin à l'installation), désinstallation propre.
- **Registre de risques génériques** (`PluginGrcmanagerRisk`, clause 6.1.2/8.2 ISO 27001) :
  titre, description, catégorie (humain/processus/physique/tiers/technique), probabilité, impact,
  niveau de risque calculé automatiquement (`RiskScoringService`, matrice probabilité x impact
  4x4), décision de traitement (accepter/mitiger/transférer/éviter), propriétaire, justification,
  date de revue, statut. Liste avec badges colorés traduits et lien cliquable vers le formulaire
  dès le premier commit (leçon déjà apprise sur le plugin jumeau glpi-vulnerability-manager,
  appliquée ici sans attendre une correction après coup).
- **Entrée de menu** sous Outils, et **4 cartes de tableau de bord** (`Hooks::DASHBOARD_CARDS`,
  signature accumulateur-safe `?array $cards = null` dès le premier commit, même leçon apprise sur
  le plugin jumeau) : risques ouverts, risques par niveau, risques par catégorie, risques en
  attente de revue.
- **CI complète** : syntaxe PHP, PHPStan, PHP_CodeSniffer, PHPUnit, Semgrep, Gitleaks (CLI, scan de
  plage de commits), Trivy, installation réelle sur GLPI 11.0.8 (Docker), hygiène du dépôt.
- **Workflows d'auto-tag et de release** (déclenchement, construction d'archive, validation
  d'installation réelle, publication GitHub Release) : préparés mais non exécutés pour de vrai à
  ce stade (Sprint 1, pas de v1.0).
- Documentation bilingue (FR/EN) : README, plan de développement par sprints, roadmap publique.
