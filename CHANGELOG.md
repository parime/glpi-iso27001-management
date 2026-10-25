# Changelog

Toutes les évolutions notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et ce projet adhère au
[Semantic Versioning](https://semver.org/lang/fr/) (`MAJEUR.MINEUR.CORRECTIF`).

## [Unreleased]

### Added

- **Menu GRC & Conformité > Tableau de bord** (issue #247) : première entrée du menu, comme
  Parc > Tableau de bord et Assistance > Tableau de bord. Elle affiche le tableau de bord
  « GRC Manager - Vue d'ensemble ISMS » avec les outils natifs de GLPI (choix du tableau de bord,
  ajout et déplacement des cartes, partage, plein écran). Cliquer sur le titre du menu y mène
  aussi. Si le tableau de bord n'est pas partagé avec le profil, la page explique comment le
  partager.
- **Tableau de bord « GRC Manager - Direction »** (issue #143) : une vue simplifiée pour le comité
  de direction, à choisir dans la liste des tableaux de bord : score de maturité, risques et
  non-conformités ouverts, actions en retard, délais NIS2 / RGPD, conformité par référentiel,
  tendance mensuelle, échéances, preuves à renouveler, fournisseurs critiques non évalués, revues
  périodiques en retard.
- Nouvelles cartes (issue #143) :
  - **Mesures mises en œuvre par référentiel** : ajout du **NIST CSF 2.0**, d'après la
    correspondance officielle publiée par le NIST (les CIS Controls ne sont pas inclus : aucune
    correspondance officielle librement disponible) ;
  - **Risques et non-conformités par mois** : courbe des 12 derniers mois pour la revue de
    direction. Chaque mois est enregistré avec le score de maturité (tâche automatique mensuelle).
    La courbe commence donc au mois de la mise à jour ;
  - **Fournisseurs critiques non évalués** : fournisseurs ayant un risque élevé ou critique ouvert
    et dont le questionnaire de sécurité n'a jamais été rempli ;
  - **Responsables en retard (accès)** : réviseurs qui ont encore des comptes à revoir dans une
    campagne de revue des accès dont l'échéance est dépassée ;
  - **Participants à repasser** : personnes dont la dernière évaluation d'une formation est
    échouée (une réussite à une session plus récente de la même formation les retire) ;
  - **Réussite par session de formation (%)** : taux de réussite des 8 dernières sessions évaluées.
- **Formations : notes et seuil de réussite** (issue #139) :
  - nouveaux champs « Note maximale du quiz » et « Seuil de réussite (%) ». Avec les deux, on
    saisit la note de chaque participant (ex. 32 / 40, virgule acceptée), affichée « 32/40
    (80 %) ». Le résultat (réussie / à repasser) est calculé depuis la note ; une note hors barème
    est ignorée ;
  - taux de réussite de la session affiché sur la fiche (« 8 sur 10 (80 %) ») ;
  - bouton **« Préparer une session de rattrapage »** : formulaire de nouvelle session pré-rempli
    avec les participants à repasser. Rien n'est enregistré avant « Ajouter » ;
  - onglets **Documents** (feuille d'émargement, support, quiz de la session) et **Historique** sur
    la fiche d'une formation.
- **Audits internes : constats, documents et rapport archivé** (issue #140) :
  - la fiche d'un audit a maintenant des onglets **Constats** (non-conformités et observations de
    l'audit, avec « Ajouter un constat » qui rattache directement le constat à l'audit),
    **Documents** (plan d'audit, preuves collectées, rapport signé) et **Historique** ;
  - onglets **Documents** et **Historique** aussi sur la fiche d'une non-conformité ;
  - quand l'audit passe au statut « Terminé », le **rapport d'audit PDF** (périmètre, mesures
    auditées, constats, conclusion) est archivé automatiquement dans son onglet Documents.
- **Calendrier de conformité : rappel avant un événement** (issue #142) : le responsable d'un
  événement planifié (audit externe, pentest, certification, exercice…) reçoit une notification
  15 jours avant son début (tâche automatique quotidienne « complianceeventreminder »,
  notification « GRC Manager - Événement de conformité à venir »). Un seul rappel par événement,
  renvoyé si l'événement est déplacé ; rien n'est modifié automatiquement.
- **Matrice des flux : rapport PDF** (issue #144) : bouton « Rapport PDF » en tête de la liste. Le
  rapport, pour l'auditeur, reprend les flux actifs par type avec leurs écarts (sans
  justification, administration exposée…), le nombre de flux désactivés et le plan d'adressage
  (réseaux IP déclarés dans GLPI).

### Fixed

- Carte « Score fournisseurs moyen » : les fournisseurs dont le questionnaire n'a jamais été rempli
  comptaient pour 0 dans la moyenne.

## [2.9.0] - 2026-10-25

### Added

- **Cartes de pilotage du tableau de bord** (issue #143) : six nouvelles cartes, ajoutées au tableau
  de bord « GRC Manager - Vue d'ensemble ISMS » et disponibles dans l'éditeur de tableaux de bord
  de GLPI : mesures mises en œuvre par référentiel (ISO/IEC 27001, NIS2, guide d'hygiène de
  l'ANSSI, d'après les correspondances indicatives du plugin), échéances de conformité à 90 jours,
  avancement de la revue des accès en cours, délais NIS2 / RGPD proches ou dépassés, score moyen
  des questionnaires fournisseurs, revues périodiques en retard. Chaque carte ouvre la liste
  correspondante et respecte les droits du profil.

### Changed

- **Écrans en Twig** (issue #199, exigence du Marketplace GLPI) : tous les formulaires, onglets et
  écrans du plugin sont rendus par des gabarits Twig (risques, mesures de l'Annexe A, exigences du
  SMSI, risques fournisseurs, audits, revues de direction, objectifs et mesures, formations,
  politiques, obligations, revue des accès, hygiène des comptes, validités des preuves, calendrier
  de conformité, revues périodiques, matrice des flux et son import, catalogue de produits). Le HTML
  écrit en PHP passe de 1226 à 241 chaînes.
- **Aides des formulaires visibles** sous chaque champ, et non plus derrière un « ? » à survoler.

### Fixed

- Après « Ajouter », la fiche de l'élément créé s'ouvre au lieu d'un formulaire vide (audits,
  obligations, mesures, revues de direction, objectifs, politiques, risques, risques fournisseurs,
  formations, catalogue de produits).
- Catalogue de produits : supprimer un produit efface ses CPE et alias ; un identifiant orphelin ne
  provoque plus d'erreur 500 à la saisie.
- Une nouvelle formation est cochée « Obligatoire » par défaut.
- Fiche d'une exigence du SMSI : le libellé « Module lié » n'affiche plus de HTML en texte.

## [2.8.0] - 2026-10-25

### Added

- **Audit de conformité technique** (issue #162) : menu GRC & Conformité > « Audit de conformité
  technique ». Un score par référentiel (ISO/IEC 27001, NIS2, RGPD), calculé à partir de cinq
  contrôles sur les données de GLPI : postes sans antivirus actif et à jour, disques non chiffrés,
  postes non inventoriés depuis 30 jours, changements clos sans validation approuvée (1 ou 2
  exigées), comptes à vérifier. Chaque contrôle liste ses écarts avec leurs références (mesure,
  article) ; « Créer le ticket de mise en conformité » ouvre un seul ticket par contrôle, avec les
  éléments liés, réutilisé tant qu'il est ouvert. Rien n'est modifié automatiquement ; un contrôle
  dont la personne ne peut pas lire les éléments n'est pas évalué.
- **Cellule de crise sur un incident réel** (issue #185) : onglet « Cellule de crise » sur chaque
  incident de sécurité. « Activer la cellule de crise » ouvre la salle de crise avec le plan de
  réponse choisi (étapes chronométrées) et ajoute :
  - la **reprise des éléments touchés** et de ce qui en dépend (analyse d'impact de GLPI), face à
    leur RTO : échéance, bouton « Rétabli », temps de reprise mesuré, « RTO dépassé » ;
  - le **journal de crise** chronologique (enregistré dans les suivis de l'incident) ;
  - la fermeture produit le rapport PDF (étapes, reprise, journal sans les suivis privés),
    archivé sur l'incident et comme preuve sur les mesures A.5.26 et A.5.29.
- **Exercices de crise** (issue #168) : menu GRC & Conformité > « Exercices de crise ». Trois
  scénarios prêts à l'emploi (rançongiciel, fuite de données personnelles, panne majeure) :
  - « Lancer l'exercice » crée un incident de sécurité fictif « [EXERCICE] » avec une tâche par étape ;
  - la salle de crise affiche le chronomètre, le délai visé de chaque étape et le bouton « Fait » ;
    une tâche passée à « Fait » depuis l'incident compte aussi ;
  - « Terminer l'exercice » produit le rapport PDF (temps de réaction, étapes dans le délai,
    participants, enseignements), archivé sur l'incident et comme preuve sur les mesures A.5.29 et
    A.5.30 de la SoA ;
  - les incidents d'exercice sont exclus du tableau de bord et du score de maturité, et filtrables
    dans la liste des incidents (critère « Exercice de crise »).
- **Rapport post-incident** (issue #169) : onglet « Rapport post-incident » sur chaque incident
  de sécurité, et bouton « Générer le rapport PDF » :
  - chronologie (déclaration, changements de statut, suivis, tâches, solution), éléments touchés,
    CVE, tickets liés, coût total ;
  - notifications réglementaires NIS2 / RGPD : tenues, en retard ou non notifiées ;
  - cause racine, enseignements tirés et actions décidées (plans d'action rattachés) ;
  - le PDF est archivé dans les documents de l'incident et comme preuve sur la mesure A.5.27 de la
    SoA. Un incident non clos produit un rapport marqué provisoire.
- **Score de maturité cyber** (issue #166), menu GRC & Conformité > Score de maturité, et carte
  du tableau de bord :
  - score de 0 à 100 et niveau de 1 (Initial) à 5 (Optimisé) ;
  - **calcul entièrement détaillé** : mesures de l'Annexe A mises en œuvre (40 %), exigences du
    SMSI conformes (25 %), preuves valides (15 %), audit interne dans les 12 derniers mois (10 %),
    formations réalisées (10 %) ; une mesure « en cours » compte pour moitié ; une composante sans
    données est écartée et son poids réparti sur les autres ;
  - pénalités : 2 points par action corrective en retard et par incident de sécurité non résolu,
    au plus 10 par catégorie ;
  - radar de mise en œuvre de l'Annexe A par thème, et évolution mensuelle (tâche planifiée
    quotidienne qui enregistre le score du mois) pour la revue de direction.

## [2.7.0] - 2026-10-25

### Added

- **Encadré « À quoi sert cette page ? »** en tête des 30 écrans du menu GRC & Conformité : à quoi
  sert l'écran, ce qui est attendu de l'utilisateur, et la référence ISO 27001 (numéro de chapitre
  ou de mesure). Repliable ; le choix est retenu par le navigateur, page par page.
- **Objectifs de reprise et plan de continuité** (issue #177, phase 2) :
  - onglet **Classification C/I/D** d'un actif : **RTO** (durée maximale d'interruption) et **RPO**
    (perte de données maximale), de 1 heure à 1 semaine. L'onglet passe en Twig ;
  - onglet **« Impact d'une panne »** :
    - **graphe** de l'actif et de ce qui en dépend, coloré par disponibilité et libellé avec le RTO ;
    - colonne RTO dans la liste des éléments touchés ;
    - alerte **« Objectifs de reprise incohérents »** quand un élément doit reprendre plus vite que
      ce dont il dépend (« l'application doit reprendre en 4 heures, mais dépend du stockage dont
      l'objectif est 24 heures ») ;
  - page « Points de défaillance uniques » : bouton **« Exporter pour le plan de continuité
    (CSV) »**. L'export liste les actifs classés en disponibilité ou dotés d'objectifs, avec leur
    disponibilité, RTO, RPO, leurs dépendances, le nombre d'éléments touchés, les points de
    défaillance unique et les risques liés.
- **Effet domino** (issue #171) : liens « cause → conséquence » entre risques, risques
  fournisseurs et incidents de sécurité (« Hameçonnage réussi » → « Compromission de compte » →
  « Fuite de données ») :
  - onglet **« Effet domino »** sur chacun : graphe des causes et conséquences sur 4 niveaux,
    liste des liens directs (retrait possible), et ajout « peut entraîner… » ou « peut être
    provoqué par… ». Un lien qui fermerait une boucle est refusé, tout comme un doublon ;
  - page **GRC & Conformité > Effet domino** : le graphe d'ensemble. Cliquer sur un élément
    l'ouvre ; un élément que la personne ne peut pas voir est compté sans être nommé ;
  - graphe Cytoscape, déjà fourni par GLPI pour son analyse d'impact (aucune bibliothèque
    ajoutée). C'est une représentation : aucune probabilité combinée n'est calculée.
- **Veille de sécurité des fournisseurs et avis du CERT-FR** (issue #167, phase 3 de #160) :
  - la tâche des vulnérabilités du parc lit aussi les **avis et alertes du CERT-FR** (flux RSS
    publics, toutes les 6 heures, conservés 180 jours), sous le même réglage « Activer
    l'enrichissement NVD » ;
  - onglet **« Veille sécurité »** sur chaque risque fournisseur : les avis des 90 derniers jours
    qui citent le fournisseur (raison sociale sans mentions juridiques ; « Dell Technologies
    France » est cherché comme « dell »), et les vulnérabilités de ses logiciels dans le parc
    (CVE, prioritaires, exploitées, machines). Le nombre d'avis des 30 derniers jours s'affiche
    sur l'onglet ;
  - **notification** au propriétaire du risque fournisseur pour chaque nouvel avis qui cite son
    fournisseur, une seule fois par avis (« Nouvel avis de sécurité concernant le fournisseur »,
    installée par défaut). Rien n'est modifié dans le registre ;
  - écran « Vulnérabilités du parc » : badge **CERT-FR** sur un logiciel cité par un avis des 30
    derniers jours (son nom, ou « les produits » de son éditeur), avec le lien vers l'avis.
- **Plan d'action (CAPA) proposé à partir d'une CVE** (issue #163) : sur l'onglet CVE d'un
  incident de sécurité, « Proposer un plan d'action » ouvre une non-conformité pré-remplie :
  - action corrective numérotée : correctif de l'éditeur et avis de sécurité (liens NVD), mise à
    jour des actifs de l'inventaire qui portent la vulnérabilité, échéance CISA si elle est
    activement exploitée, contrôle d'efficacité ;
  - action préventive : surveillance du logiciel et cycle de correctifs ;
  - sévérité selon le CVSS et le catalogue KEV. L'échéance est de 7, 30 ou 90 jours, ou celle de
    la CISA si elle est plus proche ;
  - rien n'est créé avant que l'utilisateur relise et clique sur « Ajouter ». La fiche garde
    l'origine (incident et CVE), et l'onglet CVE affiche ensuite « Plan d'action n° … ».
- **Exploitation des CVE des incidents de sécurité** (issue #172) :
  - l'onglet CVE d'un incident affiche le badge **KEV**, avec l'échéance CISA, et le score **EPSS**
    (probabilité d'exploitation dans les 30 jours). Le score est en rouge au-delà de 50 % ;
  - la tâche quotidienne des vulnérabilités met à jour l'EPSS des CVE rattachées aux incidents, en
    plus de celles du parc ;
  - un **suivi privé** est ajouté à l'incident ouvert dans deux cas : quand une de ses CVE figure au
    catalogue KEV, et quand son EPSS franchit 50 % puis à chaque hausse de 20 points. La priorité
    de l'incident n'est jamais modifiée : le suivi invite l'équipe à la revoir.
- **Tickets des vulnérabilités du parc** (issue #160, phase 2) :
  - **un ticket par logiciel à mettre à jour** (« Mettre à jour Mozilla Firefox : 12 vulnérabilité(s),
    3 machine(s) »). Il détaille les vulnérabilités les plus graves (CVE, CVSS, EPSS, KEV et
    échéance CISA) et compte les autres. Les machines touchées y sont liées (50 au plus). L'urgence
    est très haute pour une vulnérabilité exploitée ou un CVSS ≥ 9 ;
  - création automatique par la tâche planifiée, **désactivée par défaut**. Elle se règle dans la
    configuration du plugin : vulnérabilité exploitée (KEV), CVSS minimal, EPSS minimal. Chaque
    passage crée 10 tickets au plus, les plus graves d'abord ;
  - un suivi est ajouté au ticket ouvert quand plus de machines sont touchées, quand une
    vulnérabilité devient activement exploitée, ou quand plus aucune machine n'est vulnérable. Le
    ticket n'est jamais fermé, et son statut ou sa priorité ne sont jamais modifiés. Une fois le
    ticket résolu ou clos, une nouvelle vulnérabilité ouvre un nouveau ticket ;
  - bouton **ticket** sur chaque ligne de l'écran « Vulnérabilités du parc », pour une création à la
    demande sans doublon ;
  - onglet **« Vulnérabilités »** sur les ordinateurs et les autres éléments qui portent des
    logiciels. Il liste les CVE connues des logiciels surveillés installés, avec le lien vers le
    ticket.
- **Vulnérabilités du parc** (issue #160, phase 1), menu GRC & Conformité > Vulnérabilités du parc :
  - écran **« Logiciels à surveiller »** : les logiciels installés, les plus exposés (navigateurs,
    accès distant, VPN, bureautique, lecteurs PDF) et les plus répandus d'abord. « Chercher le CPE »
    propose les correspondances du dictionnaire NVD ; « Confirmer » alimente le catalogue de
    corrélation existant (produit, CPE, alias) sans ressaisie. Un logiciel sans enjeu s'ignore ;
  - pour chaque version installée d'un logiciel surveillé, NVD renvoie exactement les CVE qui la
    touchent (recherche par version). Une tâche planifiée horaire en vérifie quelques-unes à chaque
    passage, dans la limite publique de NVD, puis met à jour le catalogue CISA KEV (vulnérabilités
    activement exploitées) et les scores FIRST EPSS (probabilité d'exploitation) ;
  - écran **« Vulnérabilités du parc »** : CVE, logiciel, versions touchées, nombre de machines,
    CVSS, EPSS, badges KEV et rançongiciel. Les vulnérabilités exploitées et prioritaires (KEV,
    CVSS ≥ 9 ou EPSS ≥ 50 %) passent en premier. Une version au numéro illisible dans l'inventaire
    est comptée « à vérifier à la main », jamais déclarée saine ;
  - tout appel sortant dépend du réglage « Activer l'enrichissement NVD » (désactivé par défaut).
    Rien n'est modifié dans le parc ; les tickets automatiques sont la phase 2.
- **Catalogue de risques types d'infrastructure** (issue #178), bouton « Catalogue de risques types »
  au-dessus du registre des risques : douze risques qui se repèrent sur un schéma réseau (service
  d'administration exposé sur Internet, cartes iLO/iDRAC/IPMI joignables, réseaux qui se chevauchent
  avec un partenaire relié par VPN, réseau invités mal isolé, réseau à plat, points de défaillance
  unique — alimentation, hyperviseur, lien opérateur, annuaire —, sauvegardes sur le même site,
  schéma non tenu à jour, journaux non consultés). Chacun a sa description, ses menaces, ses mesures
  de l'Annexe A et des questions pour savoir s'il s'applique. Les risques cochés sont créés dans le
  registre au statut « Identifié », liés à leurs mesures, à évaluer ; un risque déjà importé n'est
  pas recréé.
- **Registre des dérogations de sécurité** (issue #170), menu GRC & Conformité > Dérogations : une
  exception temporaire à une politique ou à une mesure de l'Annexe A (un serveur qui ne peut pas
  encore être mis à jour, par exemple), avec justification, mesures compensatoires, responsable qui
  accepte le risque, dates de début et de fin (obligatoire) et actifs concernés. Statut demandée,
  validée (qui et quand sont enregistrés), refusée ou close ; état « en cours », « bientôt échue »
  ou « échue » d'après la date de fin. Le responsable est prévenu 15 jours avant la fin ; à
  l'échéance, un ticket de réévaluation est ouvert, lié aux actifs. Rien n'est prolongé ni clos
  automatiquement ; une nouvelle date de fin ouvre un nouveau cycle. Onglet « Dérogations » sur les
  actifs, les mesures et les politiques, avec « Nouvelle dérogation pour cet élément » ; documents
  joints (validation signée, analyse de risque).
- **Couverture des preuves** (issue #164), menu GRC & Conformité > Couverture des preuves : une
  règle relie un type d'actif (avec un sous-type facultatif, par exemple les seuls pare-feu parmi
  les matériels réseau) à une catégorie de document GLPI requise et à la mesure de l'Annexe A ou à
  l'exigence du SMSI qu'elle prouve. Onglet « Taux de couverture » : pourcentage d'actifs qui ont un
  document de cette catégorie, et liste des actifs non couverts, chacun avec un lien vers son
  onglet Documents. Les mesures et les exigences affichent leurs règles et leur jauge dans un onglet
  « Couverture des preuves ». Les actifs comptés sont ceux des entités actives.
- **Analyse d'impact d'une panne** (issue #177, phase 1), à partir de l'analyse d'impact native de
  GLPI : onglet « Impact d'une panne » sur chaque actif, qui liste tout ce qui en dépend
  (directement ou non), la disponibilité classée de chaque élément et les risques du registre
  concernés. Un élément à disponibilité haute qui ne dépend que d'une seule source signale un
  **point de défaillance unique** ; le bouton « Créer le risque correspondant » l'ajoute au
  registre, lié à l'actif, sans jamais le faire automatiquement. Page GRC & Conformité > Points de
  défaillance uniques pour toute l'instance.

### Changed

- La fiche d'un risque s'affiche avec une barre d'onglets, comme celle d'un risque fournisseur
  (nécessaire à l'onglet « Effet domino »).
- Le formulaire des non-conformités passe en Twig (champs inchangés). Après « Ajouter », la fiche
  créée s'ouvre directement.

- Les boutons au-dessus du registre des risques sont rendus en Twig (exigence du Marketplace GLPI).

### Fixed

- L'en-tête des fiches de risque, de risque fournisseur, de non-conformité et d'audit affichait
  « N/A » au lieu de leur titre.
- Dérogations (#170) : les listes « Mesure de l'Annexe A » et « Politique » ne se chargeaient pas
  (ces registres n'ont pas de champ « nom ») ; elles proposent désormais les mesures et politiques.

## [2.6.0] - 2026-10-24

### Added

- **Calendrier de conformité** (issue #142), menu GRC & Conformité > Calendrier de conformité :
  événements datés saisis (audit de certification ou de surveillance, test d'intrusion, exercice de
  crise, renouvellement de certificat…, avec prestataire, périmètre, responsable, documents) et
  échéances des autres registres, sans recopie : audits internes, revues de direction, campagnes de
  revue des accès, prochain ticket des revues périodiques, revues des politiques et des obligations,
  fin de validité des preuves. Vue par mois (toutes les échéances ou les miennes), export iCal, et
  affichage dans le planning GLPI de chaque responsable (lecture seule). Rien n'est modifié par le
  calendrier.
- **Escalader un ticket en incident de sécurité depuis le ticket** (issue #165) : section
  « Sécurité » dans le panneau principal de chaque ticket, avec le bouton « Escalader en incident de
  sécurité » (confirmation demandée) ou, une fois qualifié, le lien vers l'incident. Aussi en action
  groupée « Qualifier en incident de sécurité » sur la liste des tickets. Même action que l'onglet du
  ticket (#128) : le ticket garde son statut et son attribution, un ticket déjà qualifié n'en crée
  pas un second.
- **Hygiène des comptes** (issue #184), menu GRC & Conformité > Hygiène des comptes (A.5.16, A.5.18,
  A.8.2) : comptes GLPI actifs à vérifier dans les entités visibles — supprimés de l'annuaire
  (synchronisation LDAP native), date de fin dépassée, sans connexion depuis N jours (30 à 365) ou
  jamais utilisés, adresse e-mail ou prénom et nom en double. Un ticket de revue s'ouvre pour les
  comptes cochés ; aucun compte n'est désactivé automatiquement. Exige aussi le droit natif de
  lecture des utilisateurs. La revue périodique « comptes à privilèges » (#141) renvoie vers cet écran.
- **Matrice des flux réseau** (issue #144), menu GRC & Conformité > Matrice des flux (A.8.20 à A.8.22).
  - Un flux relie une source et une destination (un actif GLPI et/ou un réseau, une zone, une adresse),
    avec un type (interne, publication sur Internet, sortant, tunnel entre sites ou vers un partenaire,
    administration, sauvegarde), un protocole, des ports, une justification, un propriétaire et une date
    de fin éventuelle ; historique des modifications.
  - Contrôles automatiques affichés sur la fiche, dans la liste et en synthèse : sans justification,
    sans propriétaire, expiré ou expirant sous 30 jours, service d'administration (SSH, RDP, SMB, bases de
    données…) ou tous les ports publiés sur Internet. Le plugin ne modifie jamais un flux.
  - Import CSV (séparateur « ; » ou « , », en-têtes français ou anglais) avec simulation préalable, lignes
    invalides signalées par leur numéro, aucun doublon en cas de réimport.
  - La revue périodique « matrice des flux » (#141) renvoie vers cet écran.

### Added

- **Revues périodiques du SMSI** (issue #141), menu GRC & Conformité > Revues périodiques.
  - Onze revues proposées : droits d'accès, comptes à privilèges, matrice des flux, registre des
    risques, risques fournisseurs, politiques, SoA, obligations légales, test de restauration des
    sauvegardes, test du PCA/PRA, journaux de sécurité ; chacune avec une fréquence par défaut et
    les exigences qu'elle couvre.
  - Activer une revue crée un gabarit et un ticket récurrent natifs de GLPI (Assistance > Tickets
    récurrents) : titre, check-list, exigences concernées et lien vers l'écran du plugin, attribué
    au technicien ou au groupe choisi.
  - Le ticket résolu date la revue (« Dernière revue ») ; une revue active sans revue sur la
    dernière période est signalée « En retard ». Le plugin ne modifie jamais le ticket.
  - Activer une revue exige le droit de modification du plugin et les droits natifs de créer des
    tickets récurrents et des gabarits.

## [2.5.0] - 2026-10-24

### Added

- **Date de validité des preuves et alerte avant expiration** (issue #145).
  - Onglet « Validités des preuves » sur les mesures de l'Annexe A, les risques fournisseurs, les
    audits et les politiques : pour chaque document rattaché, une date de fin de validité et un
    responsable du renouvellement.
  - Tâche planifiée quotidienne « evidencereminder » : notification au responsable 30 jours avant
    l'échéance, puis un ticket « Renouveler la preuve » à l'expiration (un seul de chaque). Une
    nouvelle date réarme le rappel. Le statut de l'élément ne change jamais.
  - Liste des preuves datées et carte de tableau de bord « Preuves expirées ou à renouveler ».
  - Une validité suit l'entité de son document : la lire ou la modifier exige le droit sur
    l'élément et la lecture du document, jamais le seul droit global du plugin.
- **Taux de signature de la charte informatique (assetsign)** sur les mesures A.5.10 et A.6.3,
  par entité (« Entité racine : 45 / 50 (90 %) »), depuis la campagne de charte la plus récente.
  Badge vert dès 90 %, orange dès 60 %, rouge en dessous. Lecture seule de chiffres agrégés,
  sans dépendance de code ; absent si assetsign n'est pas actif ou ne gère pas encore les
  campagnes de charte (assetsign 2.12.0 et suivantes).

### Fixed

- **Liste « Validités des preuves »** : le bouton « Ajouter » de GLPI menait à une page introuvable ;
  il n'est plus proposé et la liste indique où saisir une date de validité (onglet de l'élément).

## [2.4.1] - 2026-10-22

### Security

- **Audit de sécurité (contrôle d'accès)**, aligné sur les revues de la marketplace GLPI des plugins
  jumeaux :
  - incidents de sécurité : s'assigner un incident exige le droit de modification (un simple
    lecteur pouvait s'assigner un incident, puis le résoudre) ;
  - revues d'accès : l'onglet d'un contrôle ne liste plus que les campagnes des entités de
    l'utilisateur, et une décision n'est acceptée que du responsable désigné ou d'un gestionnaire
    de la campagne elle-même (son entité) ;
  - fournisseurs : l'onglet « Contrats du fournisseur » exige le droit de lecture sur les contrats
    et ne liste que ceux des entités de l'utilisateur ;
  - risques : la liste des actifs à lier, les actifs liés affichés et la classification C/I/D ne
    concernent plus que les actifs visibles par l'utilisateur ; enregistrer un risque ne supprime
    plus les liens vers des actifs qu'il ne voit pas ;
  - classification C/I/D : impossible pour un actif hors du périmètre de l'utilisateur ;
  - Déclaration d'applicabilité : le demandeur ne peut plus se désigner approbateur de sa propre
    demande (séparation des tâches) ;
  - tableaux de bord : chaque carte vérifie le droit de lecture de l'objet compté, et les cartes
    d'incidents ne comptent que ceux des entités de l'utilisateur, hors corbeille ;
  - export CSV de la SoA : neutralisation des cellules interprétables comme formules.
- La vérification de la dernière version sur GitHub ne contacte plus api.github.com que si l'action
  automatique native « checkupdate » de GLPI est activée (désactivée par défaut), avec un délai total.
- CI : actions GitHub et image Gitleaks épinglées par empreinte, Semgrep à nouveau bloquant (le job
  ne pouvait jamais échouer), règles Semgrep propres au projet, contrôle d'autorisation obligatoire
  sur chaque point d'entrée, `dependency-review` sur les PR, et test de contrôle d'accès de bout en
  bout couvrant les 48 pages (profil sans droit, lecteur, gestionnaire limité à une entité, puis
  super-admin), dont la CI vérifie la couverture, et test XSS de bout en bout (charge piégée dans
  chaque champ texte libre, restituée échappée sur 25 pages). L'archive de release n'embarque plus les
  tests ni la configuration de développement.

## [2.4.0] - 2026-10-19

### Added

- **Revue périodique des accès : campagne de validation par les responsables** (issue #126).
  - **Campagne** (menu « Revues des accès ») : entité, fréquence (trimestrielle, semestrielle,
    annuelle), échéance, pilote et mesure de la SoA (A.5.18 « Droits d'accès » par défaut).
  - **Lancement** : chaque compte actif de l'entité est figé avec ses profils et groupes GLPI.
    Il est confié à son responsable hiérarchique, sinon au responsable d'un de ses groupes, sinon
    au pilote. Les responsables sont prévenus par notification.
  - **« Mes revues d'accès »** : chaque responsable, même sans droit sur le plugin, valide ou
    demande une modification ou un retrait, ligne par ligne, avec un commentaire obligatoire pour
    une demande.
  - **Ticket, jamais de changement de droit** : une demande de modification ou de retrait crée un
    ticket pour l'équipe support. La ligne est alors figée. Le plugin ne modifie aucun droit,
    compte ni statut.
  - **Relances** : la tâche quotidienne « accessreviewreminder » relance au plus une fois par
    semaine les responsables qui ont encore des comptes à revoir après l'échéance.
  - **Clôture** : un rapport PDF (comptes, profils, décisions, tickets, comptes non revus) est
    archivé comme Document sur la campagne et sur la mesure de la SoA. La campagne suivante se
    prépare en un clic d'après la fréquence.
  - **Mesures de la SoA** : la fiche d'une mesure a désormais des onglets « Revues des accès » et
    « Documents », et affiche son code dans l'en-tête.

- **Évaluation des fournisseurs : questionnaire de sécurité, contrats et ticket de réévaluation**
  (issue #127).
  - **Onglet « Questionnaire de sécurité »** sur le risque fournisseur : douze questions
    (certification, politique, localisation et chiffrement des données, MFA, sous-traitants,
    notification d'incident, sauvegardes, audits, vulnérabilités, réversibilité, clauses) avec
    oui / partiel / non / sans objet. Le score (en %) et son niveau sont indicatifs : le niveau de
    risque saisi par l'équipe n'est pas modifié. La date et l'auteur sont conservés.
  - **Onglet « Contrats du fournisseur »** : contrats GLPI du fournisseur avec leurs dates de
    début et de fin, et un badge « Échu ».
  - **Onglets Documents et Historique** : les preuves (questionnaire signé, certificats) se
    joignent au risque fournisseur. Le formulaire affiche désormais sa barre d'onglets.
  - **Ticket de réévaluation** : à l'échéance de la date de revue, la tâche « reviewreminder »
    ouvre un ticket « Réévaluer le fournisseur X » pour le propriétaire du risque, lié sur le
    formulaire. Un seul ticket tant qu'il n'est pas résolu ; le statut du risque n'est pas modifié.

- **Incidents de sécurité : qualification d'un ticket et délais de notification réglementaires**
  (issue #128).
  - **Qualifier un ticket** : l'onglet « Incident de sécurité » d'un ticket crée l'incident en
    reprenant titre, description, date, demandeur et éléments liés, puis relie les deux (onglet
    « Tickets liés » sur l'incident). Le ticket garde son statut et son attribution, et reçoit un
    suivi privé avec le lien. Un ticket n'est qualifié qu'une fois.
  - **Notifications réglementaires** : nouvelle section sur l'incident, avec les cases « Soumis à
    NIS2 » et « Violation de données personnelles ». Les échéances sont calculées depuis l'ouverture
    de l'incident : NIS2 alerte précoce 24 h, notification 72 h, rapport final 1 mois ; RGPD 72 h.
    Chacune a son statut (à faire, bientôt, dépassé, fait) et la date où l'équipe a notifié.
  - **Tâche planifiée horaire « regulatorydeadlines »** : un suivi privé est ajouté sur l'incident
    quand une échéance approche puis quand elle est dépassée, une seule fois par niveau. Ce suivi
    prévient les acteurs par les notifications GLPI habituelles. Rien n'est envoyé à une autorité.

### Fixed

- **Incidents de sécurité, onglet « Coûts » (GLPI 12)** : l'onglet échouait (erreur 500) faute des colonnes
  `date_creation`, `date_mod`, `users_id` et `users_id_lastupdater` que GLPI 12 lit sur les coûts ITIL.
  Elles sont ajoutées à la mise à jour.
- **Listes Risques et Audits internes** : avertissements PHP « Undefined variable $CFG_GLPI » et liens
  d'en-tête cassés, la configuration GLPI n'étant pas déclarée globale dans ces pages.
- **Approbation de la SoA** : après une demande ou une réponse, retour garanti sur la page d'approbation
  (la redirection reposait sur `$_SERVER['PHP_SELF']`, qui vaut `/index.php` derrière le routeur GLPI).

## [2.3.0] - 2026-10-11

### Added

- **Référentiel NIS2** (directive (UE) 2022/2555 : articles 20, 21 §2, 23 §4), relié aux contrôles
  de l'Annexe A ISO 27001 (issue #110).
- **Guide d'hygiène informatique de l'ANSSI** (42 mesures, Licence Ouverte), relié aux contrôles de
  l'Annexe A (issue #111).
- **Exigences du SMSI, articles 4 à 10** : 30 sous-articles suivis (statut, responsable, preuves,
  liens vers les modules du plugin), taux de complétude, repris dans le PDF de la SoA (issue #113).
- **Approbation de la Déclaration d'Applicabilité par la direction** : versions figées (PDF et
  empreinte SHA-256), approbateurs désignés, historique, nouvelle approbation exigée après toute
  modification de la SoA (issue #112).
- **Preuves assetsign** : les remises et restitutions signées du plugin assetsign servent de preuves
  sur les contrôles A.5.9 à A.5.11 et figurent dans le PDF de la SoA (issue #109).

### Changed

- CI : vérification sur GLPI 11.0.11 et 12.0.0 finale, suite d'intégration sur GLPI 11 et 12,
  PHPStan aussi contre les sources de GLPI 12.

### Fixed

- **GLPI 12** : les pages Audits et Risques provoquaient une erreur 500 (`Plugin::getWebDir()`
  supprimée en GLPI 12), de même que les pages de classification et d'approbation de la SoA quand
  l'élément est introuvable (`Html::displayNotFoundError()`). La CI analyse désormais aussi le
  plugin avec PHPStan contre les sources de GLPI 12.

## [2.2.0] - 2026-10-04

### Added

- **Compatibilité GLPI 12** (en plus de GLPI 11, depuis un seul et même paquet). Incompatibilités
  réelles corrigées, identifiées en faisant tourner les suites de tests sur un vrai GLPI 12.0.0-rc2 :
  - GLPI 12 type de nombreuses propriétés de ses classes de base (`CommonGLPI::$rightname`,
    `CommonDBRelation::$itemtype_1`…, `CommonITILObject::$userlinkclass`…), GLPI 11 non — et PHP
    impose à une sous-classe de reprendre exactement le type du parent, donc aucune déclaration
    unique ne fonctionne sur les deux. Les 29 classes concernées (20 pour `$rightname`, plus le
    module Incidents de sécurité : relations d'acteurs, coûts, gabarits, collection de règles)
    héritent désormais d'une classe intermédiaire (`src/Compatibility/Base/`) déclarée selon la
    version installée (propriétés typées en 12, non typées en 11) ; chaque classe fournit ses
    valeurs via des constantes (`RIGHTNAME`, `ITEMTYPE_1`…).
    Une classe et non un trait : sur PHP 8.2 à 8.4, un trait ne peut pas
    redéclarer une propriété héritée avec une autre valeur (erreur fatale, ou en PHP 8.2 valeur
    partagée en silence avec la classe de GLPI). Nouveau job CI `php-compat` qui charge chaque
    classe concernée sur le vrai cœur GLPI, sous PHP 8.2 à 8.5 × GLPI 11 et PHP 8.3 à 8.5 × GLPI 12.
  - `$DB->request('table')` (forme chaîne supprimée en GLPI 12) → forme tableau, dans
    `RiskMatrixConfig`, `SecurityIncidentModuleConfig` et `NvdConfig` — sans ce correctif,
    GLPI 12 désactivait silencieusement le plugin au démarrage.
  - `Toolbox::getURLContent()` supprimé en GLPI 12 → `Compatibility\Http` (vérification de version
    GitHub, enrichissement CVE NVD).

  Résultat : tests unitaires (228) et d'intégration (19) au vert sur GLPI 11.0.9 **et** GLPI
  12.0.0-rc2, plus un test fonctionnel du module Incidents de sécurité (création avec acteurs,
  élément lié, coût, tâche, formulaire, onglets, suppression) réussi sur les deux. Nouveau job CI
  `Installation reelle sur GLPI 12` (cycle d'installation complet + suite d'intégration).

- **`.github/dependabot.yml`** — ce dépôt était le seul des trois plugins jumeaux à n'avoir aucune
  configuration Dependabot (ni mises à jour automatiques de version, ni alertes de sécurité sur les
  dépendances) ; trouvé lors d'un audit de dépendances. Deux écosystèmes couverts (`composer`,
  `github-actions`), même convention (labels, delai de refroidissement de 7 jours, revue/assignation
  à `parime`) que `Configuration-glpi-auto`/`assetsign-glpi`.

### Changed

- **`phpstan/phpstan` mis à jour (2.2.9 → 2.2.16)**, seule dépendance réellement obsolète repérée par
  cet audit (`composer outdated`) sur ce que verrouille `composer.lock`. `phpunit/phpunit` a aussi une
  version majeure disponible (10.5.64 → 13.x) mais volontairement **non appliquée** : PHPUnit 13 exige
  PHP ≥ 8.4.1, alors que ce plugin déclare officiellement PHP ≥ 8.2 — un changement de politique de
  version minimale du tooling de dev, pas une simple mise à jour de routine, laissé à une décision
  explicite plutôt qu'appliqué silencieusement.

### Fixed

- **Lier un actif à un incident de sécurité fatalait** (`CommonITILObject::
  getRuleCollectionClassInstance()` réclame une classe `Rule{Type}Collection` pour tout objet ITIL,
  y compris ceux définis par un plugin). `RulePluginGrcmanagerSecurityIncident`/
  `RulePluginGrcmanagerSecurityIncidentCollection` existaient déjà avec un contenu correct
  (`inc/rulesecurityincident*.class.php`), mais n'étaient jamais chargées : l'autoloader natif de
  GLPI pour les classes `inc/{nom}.class.php` (`glpi_autoload()`) n'agit que sur un nom de classe
  qui commence littéralement par `Plugin` — or la convention `'Rule' . static::getType() .
  'Collection'` place systématiquement `Plugin...` au milieu du nom, jamais au début, pour n'importe
  quel objet ITIL défini par un plugin tiers. `require_once` explicite ajouté dans
  `plugin_init_grcmanager()` (pas au premier niveau de `setup.php`, qui doit rester chargeable sans
  noyau GLPI pour `SetupMenuRedefinitionTest`).
- **`tests/integration-bootstrap.php` ne rendait jamais `$kernel` réellement global** — `global
  $kernel` (code historique de GLPI, ex. les chemins dépendant de `getMainRequest()` déclenchés
  par certains hooks `CommonDBTM::add()`) n'y trouvait rien, plantant avec "Call to a member
  function getMainRequest() on null" sur tout test créant un `User`. Même correctif déjà nécessaire
  sur le plugin jumeau Configuration-glpi-auto, appliqué ici aussi (`$GLOBALS['kernel'] = $kernel;`
  avant `$kernel->boot()`).
- **L'onglet "Risques" (issue #25) et l'onglet "Classification C/I/D" (issue #26) n'apparaissaient
  jamais sur un actif personnalisé créé par un autre plugin** (`Vehicule`/`Serveur`/`Local`... via
  Configuration-glpi-auto) alors que le lien registre-de-risques/actif restait fonctionnel depuis
  le formulaire du risque lui-même (issue #88). `setup.php` construisait la liste `addtabon` de ces
  deux onglets à partir de `LinkableItemtypes::DEFAULT_ITEMTYPES` (liste fixe des seuls itemtypes
  natifs de GLPI), au lieu du résultat dynamique de `PluginGrcmanagerRisk::getLinkableItemtypes()`
  (qui, lui, interroge directement la table SQL `glpi_assets_assetdefinitions`, disponible dès la
  connexion DB). Les deux appels à `Plugin::registerClass()` utilisent désormais
  `PluginGrcmanagerRisk::getLinkableItemtypes()` — même contournement, déjà éprouvé en production,
  que `Config::getAllManageableItemtypes()` du plugin jumeau assetsign-glpi.

- **La fiche d'une Politique de sécurité affichait « N/A » comme titre de page**, au lieu du vrai
  titre de la politique — trouvé en peuplant une instance de test avec de vraies politiques.
  `front/policy.form.php` est le seul contrôleur de ce plugin à appeler `displayFullPageForItem()`
  (nécessaire pour que l'onglet natif « Documents » s'affiche, cf. le commentaire déjà présent dans
  ce fichier) ; ce chemin construit l'en-tête de page à partir de `getNameField()`, jamais surchargé
  sur `PluginGrcmanagerPolicy` alors que sa colonne d'affichage principale est `title`, pas `name`
  (cette table n'a même pas de colonne `name`). Chaque classe sœur (Risque, Audit...) utilise un
  simple `Html::header()`, qui ne consulte jamais de champ nom — aucune n'avait besoin de ce
  correctif.

## [2.1.0] - 2026-09-12

### Added

- **Taux de réussite des formations évaluées.** Deuxième indicateur manquant identifié lors de
  l'audit complet du plugin (recherche des bonnes pratiques KPI ISO 27001:2022), après le délai
  de réponse aux incidents (v2.0.0). Nouvelle colonne `assessment_result` (sans évaluation/
  réussie/échouée) sur le suivi individuel de formation, indépendante du statut de réalisation
  existant (pending/completed/exempted) : une formation peut être suivie sans évaluation formelle
  (quiz, test pratique...), auquel cas elle reste hors périmètre de ce nouvel indicateur plutôt que
  comptée comme réussie ou échouée par défaut. Nouvelle carte de tableau de bord correspondante,
  calculée uniquement sur les participants effectivement évalués. Aucune ligne existante n'est
  affectée (valeur par défaut `not_applicable`).

## [2.0.0] - 2026-09-11

### Changed

- **PHP 8.2 minimum requis** (était 8.1) — hérité du module Incidents de sécurité absorbé
  ci-dessous, qui l'exigeait déjà dans son plugin d'origine. Changement cassant pour toute
  installation encore sur PHP 8.1.

### Added

- **Absorption du module "Incidents de sécurité" (objet ITIL complet)** depuis le plugin jumeau
  glpi-security-incidents (ROADMAP.md "Version 2.0"). L'ancien registre léger de conformité ISO
  (`PluginGrcmanagerSecurityIncident`, issue #29 : catégorie/sévérité/impact C/I/D/cause racine/
  enseignements tirés/lien vers un risque) est remplacé par l'objet ITIL complet porté depuis ce
  plugin jumeau (acteurs, workflow, tâches, notifications, suivi CVE, modèles d'incident) — **les
  deux fusionnés en un seul enregistrement**, plus de double saisie entre le suivi opérationnel et
  la conformité :
  - Nouvelle entrée de menu "Incidents de sécurité" dans le secteur natif Assistance (`helpdesk`),
    aux côtés de Tickets/Problèmes/Changements — ce plugin peut désormais enregistrer des classes
    dans plusieurs secteurs de menu à la fois.
  - Les champs de classification ISO 27001 (catégorie/sévérité/impact C/I/D/cause racine/
    enseignements tirés/risque lié) s'affichent dans un accordéon dédié "Classification ISO 27001",
    aux côtés de l'accordéon "Analyse" (impact/contrôles/plan de retour arrière) également absorbé
    — les deux directement dans le panneau principal de champs, via `Hooks::POST_ITIL_INFO_SECTION`.
  - La clôture d'un incident (statut "Clos") exige toujours une cause racine et des enseignements
    tirés documentés (clause A.5.27), exactement comme le faisait l'ancien registre.
  - Suivi CVE (association en masse, format validé), modèles d'incident, et 4 cartes de tableau de
    bord natives (total, ouverts, répartition par entité/catégorie) en plus des cartes existantes
    (par statut/sévérité, mises à jour pour le nouveau vocabulaire de statuts ITIL).
  - Chaque partie (module principal, CVE, modèles, tableau de bord) reste activable/désactivable
    indépendamment via l'écran de configuration ajouté précédemment.
  - **Migration automatique et transparente** : toute donnée existante dans l'ancien registre léger
    est migrée vers le nouvel objet fusionné à la mise à jour du plugin (mapping des statuts,
    conversion du responsable en acteur assigné), sans action manuelle requise.
  - Droits dédiés (`plugin_grcmanager_securityincident`, `rule_grcmanager_securityincident`)
    conservés distincts du droit plat du reste du plugin, pour la granularité de visibilité réelle
    qu'un objet ITIL nécessite (voir/mes-incidents vs. voir-tout).
  - Vérifié en conditions réelles sur l'instance GLPI partagée : migration d'un incident réel,
    affichage des deux accordéons avec les données migrées, blocage/déblocage de la clôture selon
    la documentation, activation/désactivation de chaque interrupteur, création d'un nouvel
    incident, enregistrement des notifications (infrastructure confirmée ; aucun envoi réel testé,
    les notifications étant globalement désactivées sur cette instance), cartes de tableau de bord
    exécutées avec succès via un script Kernel réel.

- **Écran de configuration du module Incidents de sécurité.** Nouvel écran dans Configuration >
  Plugins > GRC Manager permettant d'activer/désactiver séparément le module principal, le suivi
  CVE, les modèles d'incident et les cartes de tableau de bord (`SecurityIncidentModuleConfig`,
  `glpi_plugin_grcmanager_securityincidentconfig`). Tous les indicateurs sont activés par défaut.

- **Carte de tableau de bord "délai moyen de réponse aux incidents"** (issue A.5.24-27 ISO/IEC
  27001:2022) : moyenne, en heures, du temps écoulé entre la création et la résolution des
  incidents de sécurité effectivement résolus. Complète les cartes déjà existantes (par statut/
  sévérité/entité/catégorie, qui comptent) avec un indicateur qui mesure un temps — un indicateur
  ISO 27001 classique qui n'avait pas d'équivalent parmi les cartes portées depuis le plugin
  absorbé.

- **Première suite de tests d'intégration** (`tests/Integration/`, `phpunit-integration.xml.dist`,
  `composer test:integration`) — ce plugin n'en avait aucune jusqu'ici (seulement `tests/Unit`,
  sans base de données). Portée depuis le plugin absorbé glpi-security-incidents : boot réel du
  Kernel GLPI, transaction par test. Couvre le cycle de vie complet de l'objet ITIL fusionné (CRUD,
  liaison d'actif, champs de classification ISO 27001, blocage/déblocage de la clôture, lien vers
  un risque, CVE, notifications, droits Super-Admin). La logique de mapping de la migration
  légère->fusionnée (`LegacySecurityIncidentMigrator`, y compris la conversion du vocabulaire de
  statuts) est en plus couverte séparément par la suite `unit` existante (rapide, sans DB).

### Documentation

- **Références obsolètes à glpi-vulnerability-manager et glpi-security-incidents mises à jour.**
  Les deux plugins jumeaux sont désormais archivés (le premier retiré au profit du suivi CVE de
  glpi-security-incidents à l'époque, le second absorbé dans ce même plugin ci-dessus). `README.md`/
  `README.en.md`, `ROADMAP.md`, `grcmanager.xml` et `docs/design/DEVELOPMENT_PLAN.md` reflètent
  désormais que le suivi des incidents de sécurité et des CVE est natif à ce plugin, plus besoin
  d'un dépôt distinct ni archivé.

## [1.1.5] - 2026-09-10

### Fixed

- **Le titre d'un contrôle Annexe A (liste SoA) n'était pas cliquable** — seule une colonne "ID"
  séparée, peu lisible (juste un numéro), permettait de rouvrir la fiche du contrôle. La colonne
  qu'un utilisateur lit et clique naturellement (`code`, ex. "A.5.1 - Politiques de sécurité de
  l'information") restait du texte brut malgré son affichage personnalisé
  (`getSpecificValueToDisplay()`). Retour utilisateur direct sur cette liste précise.

  Corrigé en reprenant la même convention que `CommonITILObject::rawSearchOptions()` pour sa
  propre colonne "Titre" (`'additionalfields' => ['id']`, nécessaire même pour la toute première
  colonne — confirmé en lisant le code de `Ticket`) : le lien entoure maintenant le texte formaté
  existant plutôt que d'être une colonne séparée à part.

  Vérifié en direct sur l'instance de test : la liste des 93 contrôles Annexe A affiche bien
  chaque "A.x.y - Titre" comme un lien cliquable vers la fiche correspondante.

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
