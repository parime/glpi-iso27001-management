# Dette technique connue

Journal des limites connues et compromis assumés, tenu à jour à chaque sprint (voir
[docs/design/DEVELOPMENT_PLAN.md](docs/design/DEVELOPMENT_PLAN.md) et
[DEFINITION_OF_DONE.md](DEFINITION_OF_DONE.md)).

## Sprint 1 (Infrastructure plugin)

- ~~**Matrice de risque fixe, non administrable.**~~ **Résolu au Sprint 2**, voir
  `RiskMatrixConfig`/`front/config.php` ci-dessous.
- **`showForm()` en HTML/PHP manuel, pas en Twig.** Choix délibéré pour ce sprint (simplicité,
  moins de surface à valider en conditions réelles) plutôt qu'un template Twig comme le fait le
  plugin jumeau glpi-vulnerability-manager pour ses propres formulaires. À réévaluer si les
  formulaires futurs (SoA, audits) deviennent significativement plus complexes. Le nouvel écran de
  configuration du Sprint 2 (`front/config.php`), lui, est en Twig dès le départ : un formulaire
  de grille 5x4 s'y prêtait mal en HTML manuel.
- ~~**Pas de tâche Cron.**~~ **Résolu au Sprint 2**, voir `PluginGrcmanagerRisk::cronReviewreminder()`
  ci-dessous.

## Sprint 2 (Registre de risques génériques v2)

- **Rappel de revue sans déduplication.** `ReviewReminderService::notify()` relance
  `NotificationEvent::raiseEvent()` pour chaque risque encore dû à chaque exécution du Cron
  (quotidienne par défaut) : un risque resté en retard plusieurs jours sans être traité génère une
  notification à chaque passage, pas une seule fois. Assumé pour ce sprint (« une version minimale
  et testée vaut mieux qu'une version élaborée et non testée ») : un administrateur maîtrise déjà
  la fréquence via Configuration > Actions automatiques. Une évolution possible (non retenue ici)
  serait un horodatage de dernier rappel par risque, sur le modèle du `glpi_alerts` que GLPI core
  utilise pour ses propres alertes de certificats (voir `Certificate::cronCertificate()`).
- **Écran de configuration à un seul onglet.** `front/config.php`/`config_form.html.twig` ne
  gèrent que la matrice de risque pour l'instant, contrairement à l'écran multi-onglets du plugin
  jumeau glpi-vulnerability-manager (11 écrans différents). À réévaluer en onglets si un sprint
  futur ajoute d'autres réglages ici plutôt que de multiplier les écrans de configuration.

## Sprint 3 (Déclaration d'Applicabilité / SoA)

- **Lien contrôle <-> risque en accès direct `$DB`, pas en itemtype `CommonDBRelation`.**
  `glpi_plugin_grcmanager_controls_risks` est géré par de simples méthodes statiques sur
  `PluginGrcmanagerControl` (`getLinkedRisks()`/`syncLinkedRisks()`), pas par une vraie classe
  `PluginGrcmanagerControl_Risk` sur le modèle `Left_Right` de GLPI core (ex. `Document_Item`).
  Suffisant pour un simple multi-select dans `showForm()` avec un nombre de risques par contrôle
  toujours faible ; à reconsidérer si un sprint futur a besoin de journalisation GLPI native
  (`Log`) sur ce lien, ou d'un widget de recherche/relation plus riche.
- **Pas de bouton de suppression dans l'écran, mais pas de blocage serveur non plus.**
  `showForm()` masque le bouton supprimer/purger (`candel => false`, les 93 contrôles sont un
  catalogue fixe seedé à l'installation), mais `front/control.form.php` conserve les branches
  delete/purge du contrôleur générique (même forme que `front/risk.form.php`) : une requête POST
  forgée par un compte disposant du droit `plugin_grcmanager` pourrait encore supprimer un
  contrôle. Assumé pour ce sprint (même niveau de risque que n'importe quel autre itemtype GLPI
  administrable), à revisiter seulement si un besoin réel de blocage serveur strict apparaît.
- **Justification obligatoire vérifiée côté serveur uniquement, pas de bascule JS côté client.**
  `prepareInputForAdd()`/`prepareInputForUpdate()` bloque bien l'enregistrement (message d'erreur
  réel, vérifié en direct) si `applicability` != "yes" et que `justification` est vide, mais le
  champ ne se marque pas visuellement obligatoire tant que l'utilisateur n'a pas essayé
  d'enregistrer (pas de JS conditionnel sur le `<select>` applicability). Cohérent avec le choix
  déjà fait pour `showForm()` du registre de risques (HTML/PHP manuel, pas de logique JS
  avancée) ; à réévaluer si un formulaire Twig plus riche est introduit pour ce module.

## Sprint 4 (Audits internes et CAPA)

- **Lien audit <-> contrôle en accès direct `$DB`, pas en itemtype `CommonDBRelation`.** Même
  simplification assumée qu'au Sprint 3 pour le lien contrôle <-> risque (voir ci-dessus) :
  `glpi_plugin_grcmanager_audits_controls` est géré par de simples méthodes statiques sur
  `PluginGrcmanagerAudit` (`getLinkedControls()`/`syncLinkedControls()`), suffisant pour un
  multi-select dans `showForm()` avec un nombre de contrôles par audit toujours faible.
- **`plugin_grcmanager_audits_id` sur `PluginGrcmanagerNonconformity` n'est pas une clé étrangère
  GLPI réelle.** Pas de `ON DELETE CASCADE` natif si un audit est supprimé : ses non-conformités
  restent en base avec un identifiant d'audit orphelin (affiché comme un lien vide par
  `PluginGrcmanagerNonconformity::getAuditTitle()`, pas une erreur, mais pas nettoyé non plus). La
  colonne n'utilise pas non plus un vrai `datatype => 'dropdown'` GLPI avec join natif
  (contrairement à la colonne « Auditeur »/« Responsable » qui, elle, pointe vers `glpi_users` en
  dropdown natif) : elle est en `datatype => 'specific'`, résolue par une requête directe
  (`getAuditTitle()`), filtrable uniquement par identifiant numérique brut, pas par titre d'audit.
  Assumé pour une première version plutôt que de risquer un join GLPI mal configuré non validé en
  direct ; à reconsidérer si le nombre d'audits croît au point de rendre le filtrage par ID
  impraticable.
- ~~**`severity` fait à la fois office de sévérité et de catégorie de non-conformité.** La demande
  initiale (« severity/category ») a été résolue par une seule échelle ordinale
  (mineure/majeure/critique) plutôt que deux énumérations distinctes (par exemple séparer
  « non-conformité » et « observation » au sens strict ISO 27001), pour rester simple et cohérent
  avec l'échelle probabilité x impact déjà utilisée par le registre de risques. À réévaluer si un
  besoin réel de distinguer les deux axes apparaît.~~ **Résolu (issue #27)** : nouveau champ
  `finding_type` (non-conformité/observation, vocabulaire ISO 19011) ajouté en tant qu'axe
  indépendant de `severity`, avec CAPA obligatoire uniquement pour une vraie non-conformité (voir
  `PluginGrcmanagerNonconformity::getFindingTypes()`,
  `GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService` et `src/Install/Installer.php` pour
  la migration).
- **Rappel de CAPA en retard sans déduplication**, même limite assumée que
  `ReviewReminderService::notify()` au Sprint 2 (voir ci-dessus) : une action encore en retard
  plusieurs jours après son échéance génère une notification à chaque exécution quotidienne de la
  tâche Cron, pas une seule fois.
- **Catégories de risque d'un audit stockées en liste séparée par des virgules**, pas en table de
  liaison : `risk_categories` est un `varchar` sur `PluginGrcmanagerAudit`, résolu vers/depuis
  `PluginGrcmanagerRisk::getCategories()` par simple `explode()`/`implode()`
  (`PluginGrcmanagerAudit::splitRiskCategories()`). Cohérent avec le fait que les catégories de
  risque ne sont pas des entités GLPI mais une énumération fixe, comme pour les autres colonnes
  enum de ce plugin ; le filtre de liste associé (`getSpecificValueToSelect()`) ne permet de
  filtrer que sur une seule catégorie à la fois (recherche « contient »), pas une combinaison
  ET/OU de plusieurs catégories.

## Sprint 5 (Risques fournisseurs/tiers)

- **`showForm()` en HTML/PHP manuel, pas en Twig**, même choix assumé que tous les formulaires
  précédents de ce plugin (voir Sprint 1 ci-dessus) : pas de bascule JS conditionnelle sur le champ
  Fournisseur bien qu'il soit obligatoire côté serveur (`PluginGrcmanagerSupplierRisk::
  validateSupplierAndComputeRiskLevel()`), seulement une aide textuelle (`form-hint`) et un message
  d'erreur réel si l'enregistrement est tenté sans fournisseur, vérifié en direct.
- **Pas de déduplication du rappel de revue**, même limite assumée qu'au Sprint 2 pour le registre
  générique (voir `ReviewReminderService` ci-dessus, désormais partagée par les deux tâches Cron
  `PluginGrcmanagerRisk::cronReviewreminder()` et `PluginGrcmanagerSupplierRisk::cronReviewreminder()`) :
  un risque fournisseur resté en retard plusieurs jours génère une notification à chaque exécution
  quotidienne, pas une seule fois.
- **Le champ `category` reste l'énumération générique du registre principal**
  (people/process/physical/third_party/technical), y compris sur ce registre dédié aux tiers, où
  `third_party` est donc à la fois le nom du registre et une valeur possible du champ (une entrée
  ici peut tout autant être catégorisée "technique" ou "processus" que "tiers/fournisseur" au sens
  strict) : assumé pour respecter la consigne « mêmes champs catégorie/probabilité/impact que
  PluginGrcmanagerRisk » plutôt que d'introduire une seconde énumération plus étroite ; à
  réévaluer si l'usage réel montre que la valeur "third_party" du champ catégorie n'apporte plus
  d'information utile sur ce registre spécifique.
- **`RiskAssessmentTrait` extrait au Sprint 5** (`src/Traits/RiskAssessmentTrait.php`) : les
  énumérations catégorie/probabilité/impact/traitement/statut et le calcul
  `computed_score`/`risk_level` étaient dupliqués mot pour mot entre `PluginGrcmanagerRisk` (Sprint
  1-2) et le nouveau `PluginGrcmanagerSupplierRisk`. Regroupés dans un trait plutôt que laissés
  dupliqués, pour que la matrice de notation ne puisse jamais diverger entre les deux registres.
  Non couvert par PHPStan (`phpstan.neon.dist`) comme le reste du code dépendant du runtime GLPI
  (`RiskMatrixConfig::load()`, `Dropdown`, `__()`), même raison que
  `src/Services/Risk/RiskMatrixConfig.php`.

## Sprint 6 (Formations et revues de direction)

- **Liens participants/participants-formation et participants/revue de direction en accès direct
  `$DB`, pas en itemtype `CommonDBRelation`**, même simplification assumée qu'aux Sprints 3-4 pour
  les liens contrôle <-> risque et audit <-> contrôle (voir ci-dessus) :
  `glpi_plugin_grcmanager_trainings_users` et `glpi_plugin_grcmanager_managementreviews_users` sont
  gérées par de simples méthodes statiques (`PluginGrcmanagerTraining::syncParticipants()`/
  `getParticipants()`, `PluginGrcmanagerManagementReview::syncAttendees()`/`getAttendees()`), pas
  par une vraie classe de liaison GLPI. Suffisant pour un multi-select dans `showForm()` avec un
  nombre de participants toujours faible ; à reconsidérer si un besoin réel de journalisation GLPI
  native (`Log`) sur ces liens apparaît.
- **Suppression d'un participant = perte de son historique de réalisation.**
  `PluginGrcmanagerTraining::syncParticipants()` retire la ligne
  `glpi_plugin_grcmanager_trainings_users` correspondante dès qu'un participant est désélectionné du
  multi-select puis la sauvegarde effectuée (supprimer-puis-réinsérer, même approche que
  `PluginGrcmanagerAudit::syncLinkedControls()`) : son statut/date de réalisation précédent n'est
  conservé nulle part. Assumé pour ce sprint plutôt qu'une table d'historique séparée ; à
  réévaluer si un besoin réel de conserver une trace même après retrait d'un participant apparaît.
- **Rappel de renouvellement de formation sans déduplication**, même limite assumée que
  `ReviewReminderService::notify()` au Sprint 2 et `OverdueCapaService` au Sprint 4 (voir
  ci-dessus) : un participant resté en retard de renouvellement plusieurs jours génère une
  notification à chaque exécution quotidienne de la tâche Cron
  (`PluginGrcmanagerTraining::cronRenewaldue()`), pas une seule fois.
- **`PluginGrcmanagerManagementReview` n'a volontairement ni notification GLPI native ni tâche
  Cron dédiée**, contrairement à `PluginGrcmanagerTraining` (rappel de renouvellement) et à tous
  les autres itemtypes de ce plugin ayant une échéance (revue de risque, CAPA en retard) : une
  revue de direction n'a pas de date d'échéance récurrente à surveiller au sens où l'entend ce
  plugin (`review_date` est une date de tenue, pas une date limite dépassable), donc rien à
  notifier automatiquement. Une évolution possible (non retenue ici) serait un rappel de
  planification si aucune revue n'a été enregistrée depuis un intervalle donné, sur le modèle des
  rappels de revue de risque.
- **Décisions et actions d'une revue de direction en texte libre, sans lien fort vers le mécanisme
  CAPA existant** (`PluginGrcmanagerNonconformity`). Assumé délibérément (voir le docblock de
  `inc/managementreview.class.php`) : une décision de revue de direction n'est pas toujours une
  action corrective liée à un audit (elle peut être une approbation budgétaire, un changement de
  politique, une acceptation de risque...), forcer chaque décision dans le flux CAPA
  dénaturerait ce que demande réellement la clause 9.3. À réévaluer si un besoin réel de suivi
  structuré (responsable, échéance, statut) par décision individuelle apparaît.
- **`showForm()` en HTML/PHP manuel, pas en Twig**, même choix assumé que tous les formulaires
  précédents de ce plugin (voir Sprint 1 ci-dessus).

## Sprint 7 (Tableaux de bord, consolidation)

- **Tableau de bord seedé écrasé au réinstall si un administrateur l'a personnalisé.**
  `DefaultDashboardService::seed()` fait un upsert sur une clé fixe
  (`grcmanager-isms-overview`) à chaque `plugin:install`/`plugin:install --force` : un
  administrateur qui aurait déplacé/retiré des cartes depuis l'écran natif Tableaux de bord verrait
  ses changements écrasés par une réinstallation ou une mise à niveau ultérieure du plugin. Assumé
  pour ce sprint (comportement identique à n'importe quelle configuration par défaut réinitialisée
  à chaque mise à jour) plutôt qu'une détection « déjà personnalisé, ne pas toucher » plus
  complexe ; à réévaluer si ce comportement surprend un administrateur en conditions réelles.
- **Nom du tableau de bord seedé non re-traduit par langue de session.** `__(...)` n'est évalué
  qu'une fois, au moment de l'installation : le nom stocké dans `glpi_dashboards_dashboards.name`
  reste figé dans la langue active à ce moment-là pour tous les utilisateurs ensuite, contrairement
  aux libellés des 15 cartes elles-mêmes (résolus à chaque affichage). Même limite que les autres
  valeurs persistées en base par ce plugin plutôt que résolues dynamiquement à l'affichage (ex.
  `severity`/`status` bruts stockés en base, traduits uniquement par les badges de liste).
- **Aucune nouvelle carte de tableau de bord ajoutée à ce sprint.** L'inventaire des 15 cartes
  existantes n'a révélé ni carte cassée, ni doublon, ni lacune justifiant une carte « santé ISMS »
  consolidée supplémentaire : le nouveau tableau de bord par défaut (ci-dessus) répond au besoin
  de vue d'ensemble en réorganisant les cartes déjà posées, sans en ajouter une seizième
  redondante avec les bigNumbers déjà disponibles.

## Sprint 8 (Documentation et release v1.0.0)

- ~~**`docs/TUTORIAL.md` (27 captures) ne couvre que les Sprints 1-4.**~~ **Résolu après la
  publication de la v1.0.0** : le tutoriel compte désormais 42 captures, toutes réelles (rejouées
  sur l'instance réelle du plugin avec Playwright, exactement comme les 27 captures d'origine).
  Étape 7 (registre de risques fournisseurs/tiers, Sprint 5, lien vers un vrai `Supplier` GLPI),
  étape 8 (suivi d'une formation et de la réalisation d'un participant, Sprint 6), étape 9
  (enregistrement d'une revue de direction, Sprint 6, avec démonstration de l'auto-renseignement de
  `review_date`), étape 10 (tableau de bord ISMS seedé à l'installation, Sprint 7, capturé avec les
  données de démonstration natives GLPI explicitement désactivées pour montrer les vrais chiffres)
  et étape 11 (carte de suivi de version GitHub sur l'écran Configuration, Sprint 7) ont été
  ajoutées. Le texte d'introduction et l'Étape 1 ont été corrigés : le plugin ajoute bien « sept
  écrans » au menu Outils, plus la mention du registre de risques fournisseurs fantôme qui ne
  correspondait à aucun écran réel a été retirée.
## Lien registre de risques <-> actifs GLPI/CMDB (issue #25)

- **Lien risque <-> actif en accès direct `$DB`, pas en itemtype `CommonDBRelation`.** Même
  simplification assumée pour tous les autres liens de ce plugin depuis le Sprint 3 (voir
  ci-dessus) : `glpi_plugin_grcmanager_risks_items` est géré par de simples méthodes statiques sur
  `PluginGrcmanagerRisk` (`getLinkedAssets()`/`syncLinkedAssets()`/`getRisksLinkedToItem()`), pas par
  une vraie classe de liaison GLPI. Suffisant pour un nombre d'actifs liés par risque toujours
  faible en pratique (l'issue elle-même ne décrit que des exemples à un ou quelques actifs) ; à
  reconsidérer si un besoin réel de journalisation GLPI native (`Log`) sur ce lien apparaît, ou si
  un futur besoin de recherche/filtrage riche sur les actifs liés se présente (voir aussi le point
  "aucune colonne de recherche" ci-dessous).
- **Un multi-select par itemtype liable, jamais un unique widget polymorphe
  `Dropdown::showSelectItemFromItemtypes()`.** Ce widget natif GLPI existe précisément pour ce cas
  d'usage (choisir un itemtype puis un item de ce type), mais son fonctionnement réel dépend d'un
  second appel Ajax déclenché en JS au changement du premier `<select>` — irait à l'encontre du
  choix déjà assumé pour `showForm()` depuis le Sprint 1 ("HTML/PHP manuel, pas de logique JS
  avancée", voir ci-dessus). Un multi-select par itemtype (même widget `Dropdown::showFromArray(...,
  ['multiple' => true])` que `PluginGrcmanagerControl::showForm()` pour son propre lien vers les
  risques) reste cohérent avec ce choix, au prix de lister tous les actifs d'un type sans
  pagination ni recherche - assumé pour ce qui est explicitement une v1 de la fonctionnalité,
  chaque itemtype sans aucun enregistrement dans l'instance GLPI n'affichant simplement aucune
  ligne. À réévaluer si le volume réel d'actifs par type dans une instance de production rend cette
  liste impraticable (voir aussi la limitation similaire déjà assumée pour
  `PluginGrcmanagerControl::showForm()`/tous les risques du registre).
- **Onglet retour "Risques" posé sur une liste FIXE d'itemtypes (`LinkableItemtypes::
  DEFAULT_ITEMTYPES`), pas sur le résultat dynamique de `PluginGrcmanagerRisk::
  getLinkableItemtypes()`.** Un actif personnalisé actif reste bien liable depuis le formulaire du
  risque (`getLinkableItemtypes()` l'inclut), mais ne reçoit pas l'onglet "Risques" sur sa propre
  fiche : `Plugin::registerClass()`/`addtabon` s'exécute au chargement du plugin (listener
  `InitializePlugins`), avant que GLPI ne charge les définitions d'actifs personnalisés en mémoire
  (listener `CustomObjectsBoot`, plus tardif) - même limitation de séquencement déjà documentée par
  le plugin jumeau assetsign-glpi pour sa propre `Config::getAllManageableItemtypes()`. Assumé pour
  cette version plutôt que de risquer un enregistrement d'onglet dynamique non validé en direct.
- **Aucune colonne "actifs liés" dans `PluginGrcmanagerRisk::rawSearchOptions()`.** Une relation à
  plusieurs actifs polymorphes (many-to-many, cible variable selon la ligne) ne correspond à aucun
  des `datatype` natifs du moteur de recherche GLPI (`dropdown`, `itemlink`...), tous conçus pour
  une jointure vers UNE table cible fixe (voir par exemple `Document_Item`, dont le compte de
  documents liés n'est lui-même pas non plus exposé comme colonne de recherche générique dans le
  cœur GLPI). Une fiche de détail avec le lien fonctionnel (formulaire + onglet retour ci-dessus)
  est le livrable de cette issue ; une colonne de recherche resterait un besoin à traiter sur mesure
  (ex. sous-requête `COUNT(*)` par risque, sans filtre par actif précis) si un besoin réel de tri/
  filtre sur ce critère apparaît en usage réel.

## Classification C/I/D des actifs (issue #26)

- **Table `glpi_plugin_grcmanager_assetclassifications` (pas `..._asset_classifications`).**
  L'issue suggérait un nom avec underscore ; la dérivation réellement utilisée partout ailleurs
  dans ce plugin (suffixe de classe en minuscules, sans underscore ajouté à la frontière camelCase,
  voir `src/Install/Installer.php`) donne `assetclassifications`, cohérente avec `assetclassifications`,
  `supplierrisks`, `managementreviews`... Choisi délibérément pour ne pas introduire une seule table
  au nom incohérent avec toutes les autres du même plugin.
- **Aucun nettoyage automatique quand l'actif classifié lui-même est supprimé.** Une classification
  reste en base avec un couple itemtype/items_id orphelin si le `Computer` (ou autre actif) qu'elle
  décrit est supprimé côté GLPI : même limitation déjà assumée pour
  `glpi_plugin_grcmanager_risks_items` (issue #25, voir ci-dessus), qui ne se nettoie elle non plus
  que lorsque c'est le RISQUE qui est purgé, jamais quand c'est l'actif lié qui disparaît. Un
  registre indépendant du risque qui commettrait la même impasse pour son propre côté « actif » n'a
  pas semblé justifier un mécanisme de nettoyage plus riche pour cette première version ; à
  reconsidérer si un besoin réel de cohérence stricte apparaît (ex. hook générique sur la
  suppression de tout itemtype liable, plutôt qu'un cas par cas par registre).
- **Droit d'édition de l'onglet vérifié uniquement via `UPDATE` sur le droit plat
  `plugin_grcmanager`**, jamais `CREATE` séparément pour le cas "cet actif n'a encore aucune
  ligne de classification" : `displayTabContentForItem()` n'affiche le formulaire d'édition que
  si l'utilisateur a `UPDATE`, alors que `front/assetclassification.form.php` vérifie bien `CREATE`
  pour un premier enregistrement et `UPDATE` pour une modification. Un profil qui aurait `CREATE`
  sans `UPDATE` (combinaison jamais utilisée en pratique dans ce plugin, qui accorde toujours le
  droit plat en bloc, voir `ProfileRight::updateProfileRights()` dans `src/Install/Installer.php`)
  ne verrait donc pas le formulaire alors qu'il pourrait légitimement classifier un actif vierge.
  Assumé pour rester simple (une seule condition d'affichage) plutôt que de dupliquer la logique
  add-vs-update du contrôleur dans l'onglet lui-même.
- **Onglet posé sur la même liste FIXE d'itemtypes que l'issue #25**
  (`LinkableItemtypes::DEFAULT_ITEMTYPES`), pas sur `PluginGrcmanagerRisk::getLinkableItemtypes()`
  (qui ajoute aussi les actifs personnalisés actifs) : exactement la même limitation de
  séquencement `InitializePlugins`/`CustomObjectsBoot` déjà documentée pour l'onglet "Risques" de
  l'issue #25 ci-dessus, voir son propre point pour le détail complet. Un actif personnalisé actif
  reste malgré tout classifiable *si* un risque le lie déjà (le multi-select de
  `PluginGrcmanagerRisk::showForm()` l'inclut), mais ne reçoit pas son propre onglet "Classification
  C/I/D" sur sa fiche.
- **La suggestion douce sur le champ Impact (`hasHighClassificationAmongLinkedAssets()`) refait une
  requête par actif lié**, pas une seule requête groupée : cohérent avec `getLinkedAssets()`
  lui-même qui fait déjà une requête par actif pour résoudre son nom, et avec la limitation déjà
  assumée pour ce même formulaire depuis l'issue #25 ("un nombre d'actifs liés par risque toujours
  faible en pratique"). À revoir si ce nombre cesse d'être faible en usage réel.

## Bibliothèque de politiques de sécurité versionnées (issue #28)

- **Aucun lien optionnel posé entre le contrôle SoA A.5.1 et les politiques.** L'issue suggérait
  d'envisager (sans l'imposer) un lien léger entre la ligne A.5.1 de la SoA
  (`PluginGrcmanagerControl`) et ce nouveau registre, sur le modèle de
  `getLinkedRisks()`/`syncLinkedRisks()`. Non retenu pour cette version : ce lien many-to-many
  existant relie un contrôle à un nombre potentiellement élevé de risques répartis sur les 93
  contrôles, alors qu'ici un seul contrôle fixe (A.5.1) serait concerné - une table de liaison
  entière (et son multi-select dans `PluginGrcmanagerControl::showForm()`) pour un unique contrôle
  a semblé disproportionnée par rapport au bénéfice réel, sachant que la bibliothèque de politiques
  reste de toute façon accessible en un clic depuis le menu Outils. À reconsidérer si un besoin
  réel de traçabilité contrôle <-> politique apparaît (ex. plusieurs contrôles Annexe A amenés à
  référencer des politiques spécifiques, pas seulement A.5.1).
- **Rappel de revue sans déduplication**, même limite assumée par tous les autres mécanismes de
  rappel de ce plugin depuis le Sprint 2 (`ReviewReminderService`, voir ci-dessus) : une politique
  restée en retard de revue plusieurs jours génère une notification à chaque exécution quotidienne
  de la tâche Cron (`PluginGrcmanagerPolicy::cronReviewreminder()`), pas une seule fois.
- **`PolicyReviewReminderService` n'est volontairement PAS un troisième appelant de
  `GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService`** malgré la ressemblance structurelle
  évidente (déjà généralisée au Sprint 5 pour couvrir `PluginGrcmanagerRisk` ET
  `PluginGrcmanagerSupplierRisk`) : cette dernière a la condition `status <> 'closed'` et la colonne
  `review_date` figées en dur dans sa requête, qui ne correspondent ni au statut
  brouillon/approuvé/archivé de ce registre, ni à sa colonne `next_review_date`. Plutôt que de
  généraliser encore ce service partagé avec un second paramètre (nom de colonne, valeur de statut
  à exclure), une classe dédiée, structurellement proche mais indépendante, a semblé plus lisible
  pour un seul troisième cas d'usage - à réévaluer si un quatrième registre a besoin du même
  mécanisme de rappel de revue, où une généralisation deviendrait alors probablement rentable.
  **Mise à jour** : l'issue #30, développée en parallèle, a justement généralisé
  `ReviewReminderService` une seconde fois (paramètre `$excludeCriteria`, voir sa propre section
  ci-dessous) pour son propre registre d'obligations - confirmant que le quatrième cas d'usage
  évoqué ici existe désormais. `PolicyReviewReminderService` n'a pas été migré vers cette nouvelle
  généralisation dans cette même PR pour ne pas re-tester une seconde fois un mécanisme déjà validé
  en direct sur l'instance réelle ; une factorisation ultérieure des trois/quatre services de rappel
  de revue de ce plugin en un seul reste une piste raisonnable pour un futur sprint de nettoyage.
- **`PolicyReviewReminderService` filtre en SQL sur `status`/`next_review_date IS NOT NULL` puis
  délègue la fenêtre "due" (30 jours) à `PolicyReviewReminderWindow::isDue()`, en PHP**, contrairement
  à `ReviewReminderService` qui fait tout en une seule requête SQL. Différence assumée pour rendre
  cette logique de fenêtre testable unitairement sans instance GLPI réelle (voir
  `tests/Unit/Services/Policy/PolicyReviewReminderWindowTest.php`), ce que `ReviewReminderService`
  documente lui-même explicitement comme hors de portée pour son propre équivalent inline (voir son
  docblock et `phpstan.neon.dist`). Légèrement moins efficace pour un très grand nombre de
  politiques (fetch plus large que nécessaire, filtre appliqué ligne par ligne), sans impact réel
  pour le volume attendu (quelques dizaines de politiques par organisation, pas des milliers).
- **Pas d'état "en cours de revue" séparé.** Seulement trois statuts (brouillon/approuvée/archivée) :
  une politique en cours de révision par le RSSI reste, opérationnellement, sa dernière version
  approuvée tant que la nouvelle n'est pas elle-même approuvée (`next_review_date` porte déjà seule
  le signal "à traiter bientôt", indépendamment du statut). Cohérent avec la demande de l'issue
  (« brouillon/approuvé »), pas étendu à un quatrième état non demandé.
- **`showForm()` en HTML/PHP manuel, pas en Twig**, même choix assumé que tous les formulaires
  précédents de ce plugin (voir Sprint 1 ci-dessus) : pas de bascule JS conditionnelle sur le champ
  Date d'approbation bien qu'il devienne obligatoire dès que le statut "Approuvée" est choisi
  (`PolicyLifecycle::isApprovalDateMissing()`), seulement une aide textuelle (`form-hint`) et un
  message d'erreur réel si l'enregistrement est tenté sans date d'approbation, vérifié en direct.

## Registre des obligations légales, réglementaires et contractuelles (issue #30)

- **Lien optionnel vers un risque en colonne directe (`plugin_grcmanager_risks_id`), pas une table
  de liaison many-to-many.** Contrairement au lien contrôle <-> risque du Sprint 3
  (`glpi_plugin_grcmanager_controls_risks`, plusieurs risques par contrôle), l'issue #30 demande
  explicitement une cardinalité zéro-ou-un ("une obligation correspond au plus à une entrée de
  risque précise, si tant est qu'il y en ait une") : une colonne directe (même modèle que
  `users_id`/propriétaire sur chaque autre registre de ce plugin) est plus simple qu'une table de
  liaison dédiée pour cette cardinalité, et reste cohérente avec l'esprit "simples méthodes
  statiques, pas une vraie classe CommonDBRelation" déjà assumé pour tous les autres liens de ce
  plugin (voir Sprint 3 ci-dessus) - ici, pas même besoin d'une table du tout. Voir
  `GlpiPlugin\Grcmanager\Services\Compliance\ComplianceObligationRules::normalizeLinkedRiskId()`/
  `isLinkedToRisk()` pour la logique pure testée, et le docblock de
  `PluginGrcmanagerComplianceObligation` pour le raisonnement complet.
- **`ReviewReminderService` généralisé une seconde fois (après le Sprint 5) pour accepter un
  `$excludeCriteria` optionnel.** Le service appliquait jusqu'ici inconditionnellement
  `'status' => ['<>', 'closed']`, une colonne que `PluginGrcmanagerComplianceObligation` n'a pas
  (elle a `compliance_status`, qui grade la conformité, pas si l'obligation est encore suivie).
  Généralisé avec un paramètre de constructeur dont la valeur par défaut reproduit exactement
  l'ancien comportement figé (aucun changement pour `PluginGrcmanagerRisk`/
  `PluginGrcmanagerSupplierRisk`, qui ne passent jamais cet argument) ; l'obligation, elle, passe un
  tableau vide - même une obligation `compliant` reste due à sa date de revue. Ce fichier n'a
  toujours aucun test unitaire direct (dépendance runtime GLPI, voir `phpstan.neon.dist`) : la
  logique de fenêtre de rappel (30 jours, exclusion des dates nulles) qu'il applique est dupliquée
  intentionnellement et testée dans `ComplianceObligationRules::isReviewDue()`
  (`REMINDER_WINDOW_DAYS` doit rester synchronisé entre les deux fichiers si jamais modifié).
- **`seedReviewReminderNotification()` généralisé pour un contenu de notification à 2 lignes
  configurable.** Codait en dur "Catégorie"/"Niveau de risque" (`PluginGrcmanagerRisk`/
  `PluginGrcmanagerSupplierRisk`) ; l'obligation n'a ni catégorie ni niveau de risque
  (`type`/`compliance_status` à la place), d'où un nouveau paramètre `$detailLines` avec la même
  valeur par défaut que l'ancien comportement figé pour les deux registres de risques existants.
- **Aucun onglet retour sur `PluginGrcmanagerRisk`** contrairement au lien risque <-> actifs CMDB de
  l'issue #25 : une obligation n'est pas un itemtype "liable" au sens de `LinkableItemtypes`/
  `getLinkableItemtypes()` (ce ne sont pas des actifs CMDB), et le nombre d'obligations pouvant
  citer un même risque reste faible en pratique - consulter la fiche de l'obligation elle-même
  (qui affiche le lien vers le risque, voir `riskLink()`) a été jugé suffisant pour cette première
  version plutôt que d'ajouter un second onglet "Obligations" sur la fiche de chaque risque.
- **Aucun nettoyage automatique si le risque lié est supprimé.** `plugin_grcmanager_risks_id` reste
  en base avec un identifiant de risque orphelin si ce risque est purgé (ni erreur, ni lien affiché
  puisque `riskLink()` retourne une chaîne vide dès que `getFromDB()` échoue) - même limitation déjà
  assumée pour `glpi_plugin_grcmanager_risks_items` (issue #25) et
  `glpi_plugin_grcmanager_assetclassifications` (issue #26), voir leurs points respectifs ci-dessus.

## Objectifs ISMS et suivi de KPI dans le temps (issue #32)

- **Mesures manuelles, jamais auto-calculées depuis d'autres données du plugin.** Une mesure
  (`PluginGrcmanagerObjectiveMeasurement`) est toujours saisie à la main ("à cette date, nous en
  sommes à X"), jamais dérivée automatiquement d'un décompte réel ailleurs dans le plugin (ex. le
  nombre de non-conformités récurrentes pour un objectif qui viserait explicitement à les réduire).
  Assumé délibérément pour cette première version, même philosophie "une version minimale et
  testée vaut mieux qu'une version élaborée et non testée" que le reste de ce plugin (voir Sprint 2
  ci-dessus) : un calcul automatique demanderait de définir, objectif par objectif, QUELLE requête
  du plugin nourrit sa trajectoire, un mapping qui n'a pas de réponse générique évidente. Une
  évolution possible (non retenue ici) serait un futur champ optionnel "source de calcul" sur
  l'objectif, résolu par une nouvelle tâche Cron qui insérerait alors ses propres mesures.
- **`plugin_grcmanager_objectives_id` sur `PluginGrcmanagerObjectiveMeasurement` n'est pas une clé
  étrangère GLPI réelle**, même simplification déjà assumée pour
  `PluginGrcmanagerNonconformity.plugin_grcmanager_audits_id` (Sprint 4, voir ci-dessus) : pas de
  `ON DELETE CASCADE` natif si un objectif est supprimé, ses mesures resteraient orphelines en
  base. Un objectif n'ayant pas de bouton de suppression retiré côté formulaire (contrairement aux
  93 contrôles du Sprint 3), ce cas reste possible en pratique ; assumé pour cette première version
  plutôt que de complexifier `PluginGrcmanagerObjective::prepareInputForDelete()`/un hook de purge
  dédié, à reconsidérer si des mesures orphelines s'avèrent gênantes en usage réel.
- **Historique de mesures en simple tableau chronologique, pas de graphique.** L'issue elle-même
  suggère un "historique de mesures dans le temps" sans imposer de visualisation particulière ; un
  tableau simple montre une trajectoire de façon honnête sans introduire de nouvelle dépendance de
  rendu (bibliothèque de graphiques), cohérent avec le choix déjà assumé pour `showForm()` depuis
  le Sprint 1 (HTML/PHP manuel, pas de logique JS avancée). À réévaluer si un besoin réel de
  visualisation graphique apparaît en usage réel (le tableau de bord natif GLPI, lui, offre déjà
  des rendus `pie`/`bar`/`donut` pour la répartition PAR STATUT, voir
  `DashboardCardService::objectivesByStatus()`, mais pas pour une série temporelle par objectif).
- **Suppression d'une mesure sans confirmation serveur, uniquement une confirmation JS
  `confirm()`.** Même niveau de protection que les boutons purge/delete natifs de GLPI ailleurs
  dans ce plugin (voir Sprint 3, "pas de bouton de suppression dans l'écran, mais pas de blocage
  serveur non plus") : une requête POST forgée par un compte disposant du droit `plugin_grcmanager`
  pourrait encore supprimer une mesure sans passer par la boîte de dialogue JS. Assumé pour ce
  sprint, même niveau de risque que n'importe quel autre itemtype GLPI administrable de ce plugin.
- **Lien revue de direction <-> objectifs en accès direct `$DB`, pas en itemtype
  `CommonDBRelation`.** Même simplification assumée que tous les autres liens many-to-many de ce
  plugin depuis le Sprint 3 (voir ci-dessus) :
  `glpi_plugin_grcmanager_managementreviews_objectives` est géré par de simples méthodes statiques
  sur `PluginGrcmanagerManagementReview` (`getLinkedObjectives()`/`syncLinkedObjectives()`),
  suffisant pour un nombre d'objectifs discutés par revue toujours faible en pratique.
- **`.mo` régénérés sans `msgfmt`/gettext** (outil absent de l'environnement de développement
  utilisé pour cette issue) : compilés depuis les `.po` mis à jour avec un petit script Python
  interne (format binaire GNU MO standard, vérifié par relecture avec `gettext.GNUTranslations` de
  Python avant commit, y compris pour les entrées plurielles et les entrées déjà existantes). À
  recompiler avec le `msgfmt` réel du système au prochain changement de traduction si l'outil est
  disponible dans un environnement ultérieur, pour rester sur l'outillage standard de l'écosystème
  gettext plutôt qu'un compilateur maison, conservé ici uniquement parce qu'aucune alternative
  n'était disponible.

## Registre des incidents de sécurité de l'information (issue #29)

- **`users_id` (responsable) et `description` ajoutés bien que non listés explicitement par
  l'issue.** L'issue #29 énumère `id, title, incident_date, category, severity, cia_impact, status,
  root_cause/lessons_learned, date_creation, date_mod` sans mentionner de propriétaire ni de
  description libre - ajoutés malgré tout par cohérence avec CHAQUE autre registre de ce plugin
  (risque, non-conformité, obligation, politique, audit...) qui ont tous les deux : un registre
  d'incidents sans responsable assignable ni description libre aurait été une régression
  d'utilisabilité par rapport au reste du plugin, pas une simplification justifiée par l'issue.
- **`cia_impact` en cases à cocher HTML, jamais `Dropdown::showFromArray(..., ['multiple' =>
  true])`** (le widget select2 que `PluginGrcmanagerAudit::showForm()` utilise pour son propre
  `risk_categories`, la même convention "liste de valeurs séparées par des virgules sur une seule
  colonne" à laquelle `cia_impact` se conforme par ailleurs). Délibérément différent ici : un
  multi-select select2 alimenté par un tableau PHP brut ne répond pas de façon fiable, vérifié en
  conditions réelles sur ce même projet, à une sélection simulée par un simple événement JS bas
  niveau côté test automatisé (le `<select>` natif sous-jacent ne se met pas à jour bien que
  l'affichage semble correct) - pour seulement 3 valeurs fixes (confidentialité/intégrité/
  disponibilité), un widget select2 n'apporte de toute façon aucun bénéfice réel (pas de recherche,
  pas de longue liste), donc de simples cases à cocher HTML sont à la fois plus simples ET plus
  fiables. Voir `SecurityIncidentRules::normalizeCiaImpact()` pour la logique de normalisation
  (accepte indifféremment un tableau de cases cochées ou une chaîne déjà séparée par des virgules).
- **Sévérité dupliquée depuis `PluginGrcmanagerNonconformity::getSeverities()`, pas partagée via un
  trait.** L'issue #29 demande explicitement de réutiliser la même échelle minor/major/critical.
  Plutôt que de faire dépendre `PluginGrcmanagerSecurityIncident` de
  `PluginGrcmanagerNonconformity` (ou d'extraire un trait pour un simple ensemble de 3 libellés),
  les mêmes clés/libellés sont redéfinis indépendamment ici : cohérent avec le fait qu'aucune autre
  échelle de sévérité de ce plugin n'est factorisée non plus (seul un vrai CALCUL partagé entre deux
  classes, `RiskAssessmentTrait`, l'est - une simple coïncidence de vocabulaire entre deux
  registres indépendants n'a pas semblé justifier un couplage supplémentaire). Si cette échelle
  devait un jour évoluer, les deux définitions devront être mises à jour ensemble.
- **`linked_itemtype`/`linked_items_id` restreints à `Ticket`/`Problem` uniquement**
  (`SecurityIncidentRules::ALLOWED_LINKED_ITEMTYPES`), pas une liste dynamique comme
  `PluginGrcmanagerRisk::getLinkableItemtypes()` (issue #25, qui inclut aussi les actifs CMDB et les
  définitions d'actifs personnalisés actives) : l'issue #29 est explicite ("cet incident de sécurité
  correspond à ce Ticket/Problem GLPI"), un incident de sécurité n'a pas vocation à référencer un
  ordinateur ou un logiciel directement de cette même façon (ce lien-là existe déjà, indirectement,
  via le risque éventuellement lié à l'incident et ses propres actifs liés).
- **Aucun onglet retour "Incidents de sécurité" sur `Ticket`/`Problem`.** Contrairement à l'onglet
  "Risques" que l'issue #25 ajoute sur chaque actif liable, aucun onglet retour n'a été ajouté sur
  la fiche `Ticket`/`Problem` elle-même pour lister les incidents de sécurité qui la référencent :
  le nombre d'incidents par ticket reste au plus 1 dans le sens direct (colonne, pas une table de
  liaison), et l'issue ne demande pas explicitement cette vue inversée. À réévaluer si un besoin réel
  de visibilité depuis la fiche Ticket/Problem apparaît en usage réel.
- **Aucun mécanisme de rappel/Cron pour ce registre**, contrairement à la plupart des autres
  registres de ce plugin (`ReviewReminderService`, `OverdueCapaService`...) : l'issue #29 ne décrit
  aucune date d'échéance récurrente à surveiller (`incident_date` est une date de survenue, pas une
  date limite dépassable, même raisonnement déjà appliqué à `review_date`/`review_date` vs.
  `PluginGrcmanagerManagementReview` au Sprint 6) - rien à notifier automatiquement pour cette
  première version.
- **`plugin_grcmanager_risks_id` et `linked_itemtype`/`linked_items_id` ne sont pas de vraies clés
  étrangères GLPI** (pas de `ON DELETE CASCADE` natif) : même simplification déjà assumée pour tous
  les liens optionnels similaires de ce plugin (`PluginGrcmanagerComplianceObligation.
  plugin_grcmanager_risks_id`, issue #30 ; `PluginGrcmanagerNonconformity.
  plugin_grcmanager_audits_id`, Sprint 4). Un risque ou un ticket/problem supprimé laisse
  l'incident avec une référence orpheline (aucune erreur : `riskLink()`/`ticketLink()` retournent
  simplement une chaîne vide ou un libellé "(supprimé)").
- **Pas de test unitaire dédié pour la migration (`Installer.php`)**, comme pour tous les autres
  registres de ce plugin (dépendance runtime GLPI `$DB`/`Migration`, voir `phpstan.neon.dist`) :
  l'idempotence de la migration (table créée une seule fois, `plugin:install --force` répété sans
  erreur ni doublon) a été vérifiée manuellement contre l'instance Docker partagée plutôt que par un
  test automatisé, cohérent avec le fait qu'aucun `InstallerTest.php` n'existe nulle part ailleurs
  dans ce projet non plus.

## Plan d'action de traitement des risques (issue #31)

- **`.mo` régénérés sans `msgfmt`/gettext**, même limite déjà documentée pour l'issue #32 ci-dessus
  (outil absent de l'environnement de développement utilisé pour cette issue) : compilés depuis les
  `.po` mis à jour avec un petit script Python interne, ré-écrit intégralement (pas un simple ajout
  binaire) à partir d'un parseur PO minimal couvrant les entrées existantes ET les nouvelles,
  vérifié par relecture avec `gettext.GNUTranslations` de Python avant commit (chaîne d'en-tête
  `Plural-Forms`/`Content-Type` incluse, formes plurielles testées). À recompiler avec le `msgfmt`
  réel du système au prochain changement de traduction si l'outil est disponible dans un
  environnement ultérieur.

- **Table enfant one-to-many (`PluginGrcmanagerRiskTreatmentAction`) plutôt que des champs plats
  façon CAPA sur `PluginGrcmanagerRisk` lui-même.** L'issue laissait le choix ouvert entre les deux.
  Un CAPA de non-conformité a exactement une action corrective ET une action préventive, deux
  champs texte fixes suffisent. Un plan de traitement de risque compte en pratique souvent plusieurs
  actions indépendantes (« corriger le système » ET « ajouter une supervision » ET « former les
  équipes »), chacune avec son propre responsable/échéance/statut suivi jusqu'à sa propre clôture -
  une seule paire de champs texte aurait forcé à tout entasser dans un seul bloc non structuré,
  perdant le suivi individuel que l'issue demande explicitement. Ce plugin a déjà un précédent direct
  pour ce choix : `PluginGrcmanagerObjectiveMeasurement` (issue #32), un enfant one-to-many sans menu
  ni écran de recherche propre, ajouté/mis à jour/supprimé uniquement depuis le formulaire de son
  parent - repris ici quasi à l'identique plutôt que le lien polymorphe simple `$DB` type
  `glpi_plugin_grcmanager_risks_items` (issue #25), qui convient à un simple ensemble de références
  sans donnée propre par ligne, pas à une entité qui porte elle-même statut/échéance/responsable.
- **`overdue` n'est PAS un des statuts stockés (`planned`/`in_progress`/`done`), contrairement à ce
  qu'une première lecture de l'issue pouvait suggérer.** Chaque autre notion de « retard » déjà
  présente dans ce plugin (CAPA, revues de risque, renouvellement de formation, revue de politique)
  est une condition DÉRIVÉE (échéance dépassée ET statut non terminal), jamais une valeur choisie
  dans un menu déroulant - voir `GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService` et
  TECH_DEBT.md Sprint 4. `TreatmentPlanRules::isOverdue()` suit exactement cette même convention
  établie, pour que le badge affiché sur la fiche du risque, la carte de tableau de bord et la tâche
  Cron de rappel ne puissent jamais diverger sur ce qui compte comme « en retard ».
- **Un risque « à mitiger »/« à transférer » ne peut plus être clôturé sans au moins une action de
  traitement enregistrée (validation serveur réelle qui bloque l'enregistrement).** L'issue demandait
  seulement que le plan soit « pertinent/quasi-obligatoire » pour ces deux décisions, sans imposer
  explicitement un blocage à la clôture. Le blocage a été ajouté malgré tout, en miroir exact de la
  règle déjà en place sur `PluginGrcmanagerNonconformity` (action corrective obligatoire pour
  clôturer/vérifier une vraie non-conformité, voir `CapaRequirementService` et TECH_DEBT.md Sprint
  4) : sans cette vérification, la clause 8.3/6.1.3 (mise en œuvre EFFECTIVE du traitement) resterait
  aussi peu vérifiée qu'avant cette issue, un risque pourrait être déclaré « clôturé » sans qu'aucune
  action n'ait jamais été enregistrée. Seule l'EXISTENCE d'au moins une action est vérifiée, pas que
  toutes les actions soient elles-mêmes au statut « réalisée » - cohérent avec la philosophie
  « version minimale et testée » de ce plugin, à réévaluer si un besoin réel de blocage plus strict
  apparaît en usage réel.
- **Section « Plan de traitement du risque » affichée EN DEHORS du `<form>` principal du risque
  (juste après `showFormButtons()`), pas littéralement « juste après Justification » comme les
  sections des issues #25/#26 sur cette même fiche.** Contrainte HTML, pas un choix de confort : ce
  mini-CRUD poste vers un contrôleur différent (`front/risktreatmentaction.form.php`) de celui du
  risque lui-même, et un `<form>` HTML ne peut pas en contenir un second - exactement la même
  contrainte déjà documentée et résolue de la même façon par
  `PluginGrcmanagerObjective::showMeasurementHistory()` (issue #32, voir son propre docblock). Les
  sections des issues #25 (actifs liés)/#26 (indice de classification) n'ont pas cette contrainte :
  elles soumettent leurs valeurs dans le MÊME formulaire que le risque, via de simples
  multi-select/texte, pas un contrôleur indépendant avec sa propre identité par ligne. Une petite
  aide contextuelle est malgré tout affichée au plus près de la décision elle-même (juste sous le
  champ Traitement) pour limiter l'écart avec le placement demandé.
- **`PluginGrcmanagerRisk::post_purgeItem()` nettoie désormais aussi
  `glpi_plugin_grcmanager_risktreatmentactions`, contrairement à `PluginGrcmanagerObjectiveMeasurement`
  qui, elle, reste orpheline quand son objectif parent est supprimé (voir TECH_DEBT.md issue #32).**
  Décision volontairement différente de son propre précédent le plus proche : ce hook existe déjà sur
  `PluginGrcmanagerRisk` (issue #25, nettoyage de `glpi_plugin_grcmanager_risks_items`), le coût
  marginal d'un second `$DB->delete()` est nul, et une action de traitement orpheline ne serait, elle,
  plus jamais visible ni supprimable ensuite (aucun menu, aucun écran de recherche propre à cet
  itemtype, contrairement à un objectif ISMS qui, lui, reste consultable même après suppression d'une
  revue de direction qui le référençait).
- **Limité à `PluginGrcmanagerRisk`, pas étendu à `PluginGrcmanagerSupplierRisk`** bien que les deux
  partagent le même `treatment`/`status` via `RiskAssessmentTrait` : l'issue #31 ne mentionne que « le
  registre de risques », pas le registre fournisseurs/tiers. Le lien créé ici
  (`PluginGrcmanagerRiskTreatmentAction`) est spécifique à `PluginGrcmanagerRisk`
  (`plugin_grcmanager_risks_id`), pas générique par itemtype ; à réévaluer si un besoin réel de plan
  de traitement pour un risque fournisseur apparaît (une généralisation à la
  `ReviewReminderService`/`$itemtype` paramétrable serait alors le point de départ naturel).
- **Aucune colonne "actions de traitement" dans `PluginGrcmanagerRisk::rawSearchOptions()`.** Même
  raisonnement déjà documenté pour l'absence de colonne "actifs liés" (issue #25 ci-dessus) : une
  relation one-to-many n'a pas de `datatype` natif du moteur de recherche GLPI adapté à un simple
  ajout de colonne : la fiche de détail (formulaire + plan de traitement inline) est le livrable de
  cette issue, une colonne de recherche (ex. compteur d'actions en retard par risque) resterait à
  construire sur mesure si un besoin réel de tri/filtre sur ce critère apparaît en usage réel.

- **Le module Incidents de sécurité absorbé (ROADMAP.md "Version 2.0") n'a que fr_FR/en_GB, pas
  les 5 langues du plugin jumeau glpi-security-incidents d'origine (de_DE/it_IT/es_ES en plus).**
  Choix délibéré à l'absorption : le reste de GRC Manager n'a lui-même que ces deux langues,
  ajouter 3 langues supplémentaires pour un seul module aurait créé une incohérence de couverture
  entre modules plutôt que de la résoudre. À réévaluer si une demande réelle de parité de
  traduction complète apparaît (traduction des ~30 nouvelles chaînes utilisateur du module dans les
  3 langues manquantes, pas un chantier de traduction du plugin entier).
- **La nouvelle suite d'intégration (`tests/Integration/`, `phpunit-integration.xml.dist`) n'a pas
  pu être exécutée avec succès sur l'instance de développement partagée** (qui héberge plusieurs
  plugins actifs en même temps). Cause identifiée précisément, pas une simple hypothèse : chaque
  plugin actif embarque sa propre copie de développement de `phpunit/phpunit` dans son propre
  `vendor/`, et le chargement de classes PHP au niveau du process (déclenché par
  `Glpi\Kernel\Kernel::boot()`, qui charge le code de TOUS les plugins actifs) fait résoudre des
  classes internes `PHPUnit\TextUI\...` depuis la copie d'un AUTRE plugin actif plutôt que celle de
  ce plugin - confirmé en désactivant tour à tour différents plugins voisins : le plugin blâmé dans
  la trace change à chaque fois, mais l'erreur persiste tant qu'au moins un autre plugin actif
  reste présent. N'affecte que cette instance de développement multi-plugins ; la CI réelle
  (`real-glpi-install`) installe une instance GLPI fraîche avec uniquement ce plugin, donc cette
  collision ne peut pas s'y produire. La logique métier la plus délicate de cette suite (le mapping
  de migration, `LegacySecurityIncidentMigrator`) est de toute façon couverte séparément et avec
  succès par la suite `unit` (rapide, sans DB, sans ce risque de collision) ; le reste a déjà été
  vérifié manuellement en conditions réelles (requêtes HTTP réelles contre l'instance) lors de
  l'absorption du module. À réévaluer : soit exécuter cette suite dans un environnement dédié à un
  seul plugin (comme le fait déjà `real-glpi-install` en CI), soit isoler `phpunit/phpunit` des
  dépendances chargées par chaque plugin en production.
- **`docs/TUTORIAL.md` n'a pas été mis à jour avec le nouveau module Incidents de sécurité.** Son
  format (une capture d'écran réelle par étape) n'a pas pu être respecté sans repasser par un
  navigateur réel pour capturer chaque étape - à faire dans une itération séparée plutôt que
  d'insérer des captures manquantes ou une section sans capture qui romprait le format du reste du
  document.

- **`docs/design/` ne contient qu'un seul document (`DEVELOPMENT_PLAN.md`), pas d'ADR dédiées.**
  Évalué lors de la même revue : il n'existe pas de série de fichiers "Architecture Decision
  Record" formels comme le ferait un projet plus mature. Jugé suffisant pour l'instant (pas
  bloquant pour la v1.0.0) car les décisions d'architecture significatives sont déjà documentées,
  juste dispersées différemment : `DEVELOPMENT_PLAN.md` donne la vue d'ensemble (répartition
  `inc/`/`src/Services/`/`src/Install/`/`front/`), et chaque choix de conception concret avec sa
  justification vit dans ce fichier `TECH_DEBT.md`, sprint par sprint, ce qui couvre en pratique le
  même besoin (« pourquoi tel choix a été fait, à quoi faire attention si on le change ») qu'une
  ADR. À réévaluer si le projet gagne des contributeurs externes qui auraient besoin d'un point
  d'entrée unique de type `ARCHITECTURE.md` plutôt que de devoir lire tout `TECH_DEBT.md`.
