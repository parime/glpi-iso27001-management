[🇫🇷 Français](ROLES.md) | [🇬🇧 English](ROLES.en.md)

# Parcours par rôle

Chaque parcours tient en quelques étapes : ce que fait la personne, où elle clique et ce qu'elle
obtient. Les chemins sont ceux de l'interface en français ; le menu du plugin s'appelle
**GRC & Conformité** et compte six rubriques (Tableau de bord, Risques, Conformité, Audits et
amélioration, Opérations, Sécurité technique). Dans chaque rubrique, les autres pages s'ouvrent par
la barre d'onglets en tête de page. Pour le détail écran par écran, avec captures, voir le
[tutoriel](TUTORIAL.md).

Les exemples utilisent des données fictives : une société « Exemple SA », sa filiale « Filiale Nord »
et des personnes inventées (Alice Martin, RSSI ; Bruno Petit, responsable informatique ; Claire
Durand, directrice générale).

| Rôle | Ce qu'il fait dans GRC Manager | Parcours |
|---|---|---|
| Administrateur du plugin | Installe, donne les droits, règle le plugin | [Administrateur](#administrateur-du-plugin) |
| RSSI, responsable du SMSI | Tient les risques, la SoA de chaque entité, prépare la revue de direction | [RSSI](#rssi-ou-responsable-du-smsi) |
| Propriétaire de mesure ou de risque | Traite ce qui lui est confié | [Propriétaire](#propriétaire-de-mesure-ou-de-risque) |
| Auditeur | Conduit un audit, demande et examine les preuves | [Auditeur](#auditeur) |
| Direction | Approuve, accepte les risques, suit le tableau de bord | [Direction](#direction) |

---

## Administrateur du plugin

Objectif : un plugin installé, des profils qui voient ce qu'il leur faut, et des réglages adaptés à
l'organisation. Compter une demi-heure.

1. **Installer et activer** : déposer le dossier `grcmanager` dans `plugins/` (voir
   [Installation](../README.md#installation)), puis **Configuration > Plugins**, ligne « GLPI GRC
   Manager », boutons **Installer** puis **Activer**. Le menu **GRC & Conformité** apparaît.
2. **Donner les droits** : **Administration > Profils**, ouvrir un profil, onglet **GRC Manager**.
   Cinq droits, à donner séparément :
   - **Gouvernance de la sécurité (SMSI)** : le droit général (SoA, audits, politiques, preuves...) ;
   - **Registres sensibles** (risques, indicateurs de risque, vulnérabilités, flux réseau) : au RSSI
     et aux propriétaires de risques seulement, car ces registres décrivent les points faibles de
     l'organisation ;
   - **Continuité d'activité** (processus métier, BIA) ;
   - **Protection des données personnelles** (registre RGPD des traitements, violations) : au DPO ;
   - **Incidents de sécurité** et leurs règles, pour l'équipe de réponse.

   Profil type : le RSSI a tout ; un technicien a la lecture du SMSI ; la direction n'a besoin
   d'aucun droit pour approuver (voir [Direction](#direction)).
3. **Régler les entités** : les risques, les mesures de la SoA et ses versions appartiennent à une
   entité GLPI (depuis la version 2.53.0). Après une mise à jour, tout est dans l'entité racine :
   rangez les risques de chaque filiale par une modification en masse (colonne « Entité ») dans
   **GRC & Conformité > Risques**. Voir [Périmètres](../README.md#périmètres-entités-glpi).
4. **Ouvrir la configuration** : **Configuration > Plugins**, cliquer sur « GLPI GRC Manager » (ou
   sur l'icône de clé de sa ligne). Tous les réglages sont sur cette page, par section :
   - **Référentiels de conformité** : activer ceux que l'organisation suit (NIS2, DORA, ISO 27701...) ;
   - **Matrice de risque** : libellés et seuils probabilité x impact, taille 4 x 4 ou 5 x 5 (écran
     de correspondance avant toute conversion du registre) ;
   - **Appétence et tolérance au risque** : groupe « Direction », seul habilité à accepter un risque
     au-delà de la tolérance ;
   - **Audits internes** : groupe du RSSI, qui lit les documents réservés des audits ;
   - **Contrôles de cohérence du SMSI** : règles actives et seuils (tolérance en jours, longueur
     minimale d'une justification...) ;
   - **Liens d'abonnement au calendrier** : durée de vie du jeton dédié (365 jours par défaut) ;
   - **Approbation des politiques de sécurité par la direction** : désactivée par défaut, approbateurs
     et règle de validation ;
   - **Incidents de sécurité**, **Enrichissement des CVE (NVD)**, **Surveillance des fuites de
     données** : modules et appels sortants, chacun désactivé tant qu'il n'est pas réglé.
5. **Vérifier** : se connecter avec un profil délégué limité à « Filiale Nord » et ouvrir
   **GRC & Conformité > Risques** : seuls les risques de la filiale apparaissent.

Options : avec le plugin [Inventaire Docker](https://github.com/parime/glpi-docker-inventory) actif,
ses données deviennent des preuves (A.5.9, A.8.8, A.8.9, A.8.32) sans réglage supplémentaire ; il
en va de même avec les plugins assetsign et Wazuh.

## RSSI ou responsable du SMSI

Objectif : un registre des risques à jour, une SoA approuvée pour chaque entité qui en a besoin, et
une revue de direction préparée avec des chiffres.

**Risques**

1. **GRC & Conformité > Risques**, bouton **Ajouter** : catégorie, probabilité, impact (le niveau
   se calcule), propriétaire, date de revue. Exemple : « Départ d'un administrateur sans passation »,
   catégorie Humain, propriétaire Bruno Petit.
2. Pour démarrer vite : **Catalogue de risques types** (au-dessus de la liste) pour importer des
   risques courants, ou **Import / export CSV** pour reprendre un registre tenu en tableur (analyse
   à blanc avant l'import).
3. Sur la fiche du risque, choisir la décision de traitement (accepter, mitiger, transférer, éviter)
   et remplir le **Plan de traitement du risque** : une action, un responsable, une échéance.
4. **Cartographie des risques** (au-dessus de la liste) pour présenter la matrice ; onglet
   **Appétence et tolérance au risque** de la rubrique pour voir les risques qui dépassent le seuil.

**SoA par entité**

5. **GRC & Conformité > Conformité** (page « Mesures de l'Annexe A ») : pour chacune des 93 mesures,
   indiquer si elle s'applique, pourquoi et où en est sa mise en œuvre. L'en-tête de la liste
   indique la SoA suivie (« SoA propre » ou « SoA héritée »).
6. Dans une filiale qui a ses propres mesures : changer d'entité active pour « Filiale Nord », puis
   **Créer la SoA de cette entité**. La copie reprend applicabilité et justifications ; chaque
   mesure reste à revoir et à mettre en œuvre dans la filiale.
7. Bouton **Approbation par la direction** de la même liste : **Figer et envoyer pour approbation**
   aux approbateurs choisis. La version figée est un PDF daté, avec son empreinte, conservé dans les
   documents GLPI. Toute modification ultérieure de la SoA demande une nouvelle version.

**Avant un audit et avant la revue de direction**

8. **GRC & Conformité > Tableau de bord**, onglet **Contrôles de cohérence du SMSI** : les écarts du SMSI
   (risque sans propriétaire, mesure mise en œuvre sans preuve, preuve expirée, politique non revue...)
   classés par gravité, chacun avec le lien vers la fiche à corriger. Rien n'est corrigé
   automatiquement. Export CSV ou PDF pour suivre la préparation.
9. Même rubrique : **Score de maturité** et **Évaluation par référentiel** donnent les chiffres de
   la revue ; dans une entité parente, l'onglet **Périmètres** du tableau de bord compare les
   filiales (SoA suivie, taux de mise en œuvre, dernière version approuvée, risques ouverts).
10. **GRC & Conformité > Audits et amélioration**, onglet **Revues de direction**, bouton
    **Ajouter** : date, participants, sujets traités, objectifs du SMSI revus, décisions et actions.
    La norme demande cette revue à intervalles planifiés (ISO 27001, 9.3) ; une fois par an au moins
    est l'usage.

Les preuves fournies par d'autres plugins apparaissent seules sur la fiche des mesures concernées :
par exemple, avec Inventaire Docker, le champ **Preuves de l'inventaire Docker** de la mesure A.5.9
liste les services inventoriés et la date du dernier import réussi de chaque connexion.

## Propriétaire de mesure ou de risque

Objectif : savoir ce qui est attendu de soi, et le faire, sans parcourir tout le SMSI.

1. Menu **GRC & Conformité > Mon espace GRC**. Le nombre entre parenthèses compte ce qui vous
   attend. L'entrée apparaît aussi pour une personne sans droit sur le plugin dès qu'elle a quelque
   chose à faire.
2. La page liste, triés par échéance, retards en rouge : risques à revoir, actions de traitement,
   tests d'efficacité des mesures, preuves à renouveler, non-conformités, revues périodiques et
   revues des accès, politiques à lire ou à revoir, formations, preuves d'audit à fournir... Seuls
   les éléments que vous avez le droit de voir sont listés.
3. Ouvrir chaque ligne et agir : par exemple, sur une action de traitement en retard, mettre à jour
   son statut et sa date de réalisation ; sur une preuve à renouveler, joindre le nouveau document.
4. Pour voir vos échéances dans votre agenda : **GRC & Conformité > Tableau de bord**, onglet
   **Calendrier de conformité**, bouton **S'abonner**, puis **Créer un lien d'abonnement**. Copiez
   le lien tout de suite : il n'est montré qu'une fois. Ce lien porte un jeton propre au calendrier
   (il n'ouvre ni GLPI ni son API), expire après la durée réglée par l'administrateur et se régénère
   ou se révoque depuis la même fenêtre. Les anciens liens fondés sur le jeton personnel GLPI ne
   fonctionnent plus : créez-en un nouveau.

Raccourci : sur **GRC & Conformité > Risques**, le bouton **Mes risques** filtre la liste sur les
risques dont vous êtes propriétaire.

## Auditeur

Deux situations : l'auditeur interne qui conduit l'audit dans GLPI, et l'auditeur (interne ou
externe) qui consulte seulement.

**Conduire un audit interne**

1. **GRC & Conformité > Audits et amélioration** (page « Audits internes »), bouton **Ajouter** :
   périmètre, dates, auditeurs. Onglet **Équipe** pour l'équipe d'audit ; ses documents de travail
   restent réservés à l'équipe et au groupe du RSSI.
2. Onglet **Demandes de preuves** de l'audit : ajouter chaque pièce attendue (mesure concernée,
   personne désignée, échéance) ou **Importer la liste de l'auditeur (CSV)**.
3. La personne désignée dépose ses documents depuis **Mes demandes de preuves**, accessible depuis
   Mon espace GRC, même sans droit sur le plugin. Elle est relancée par e-mail avant l'échéance puis
   chaque semaine en cas de retard.
4. Dans le même onglet, **Accepter** chaque preuve soumise ou **Demander de compléter** avec le motif.
   L'avancement (acceptées, à revoir, à fournir) s'affiche en tête.
5. Saisir les **Constats** ; chaque non-conformité ouvre une fiche dans **Non-conformités** avec ses
   actions correctives. Le rapport PDF est archivé à la clôture.

**Donner un accès en lecture à un auditeur**

6. Onglet **Demandes de preuves** de l'audit, section **Accès des auditeurs (lecture seule)** :
   **Donner l'accès en lecture à cet audit** au compte de l'auditeur, avec une date de fin.
7. L'auditeur ouvre son **Espace auditeur** : depuis Mon espace GRC (« Audit à consulter comme
   auditeur ») ou, dans l'interface simplifiée, par l'entrée **GRC Manager** du menu. Il voit les
   mesures, l'avancement, les preuves soumises et les constats ; chaque téléchargement est tracé
   (onglet **Accès aux documents** de l'audit). L'accès prend fin à la date fixée et se révoque à
   tout moment.

## Direction

Objectif : décider en quelques clics, sans devoir connaître le plugin.

1. **Approuver la SoA** : un e-mail signale la demande. Ouvrir **Mon espace GRC** (ligne
   « Approbation de la SoA »), lire la version figée (PDF), puis **J'approuve cette version** ou
   **Refuser** avec un commentaire. Aucun droit sur le plugin n'est nécessaire ; le demandeur ne peut
   pas approuver sa propre demande.
2. **Approuver une politique** (si l'approbation des politiques est activée) : même chemin, ligne
   « Politique à approuver ». Un refus demande un commentaire, pour que l'auteur corrige.
3. **Accepter un risque au-delà de la tolérance** : seuls les membres du groupe « Direction » réglé
   par l'administrateur le peuvent. L'acceptation, datée et nominative, porte la mention
   « Direction » sur la fiche du risque.
4. **Suivre** : **GRC & Conformité > Tableau de bord**, vue **Vue d'ensemble ISMS** (risques,
   mesures, incidents, échéances) ; onglet **Score de maturité** pour une note de 0 à 100 et son
   évolution mensuelle ; vue **Périmètres** pour comparer les filiales. Ces pages demandent la
   lecture du SMSI.
5. **Revue de direction** : le RSSI la prépare ; la direction valide les décisions et actions
   enregistrées dans **Audits et amélioration > Revues de direction**.
