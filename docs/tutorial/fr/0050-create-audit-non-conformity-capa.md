### Créer un audit et une non-conformité, jusqu'à la clôture CAPA

Depuis **GRC & Conformité > Audits et amélioration**, la liste est vide tant qu'aucun audit n'existe.

![Liste des audits internes vide](screenshots/tutorial/17-audit-list-empty.png)

Un audit se définit par un titre, un statut, une date planifiée et un périmètre libre.

![Formulaire de création d'un audit](screenshots/tutorial/18-audit-form-filled.png)

![Audit enregistré](screenshots/tutorial/19-audit-saved.png)

Les documents de l'audit (plan d'audit, preuves collectées, rapport signé) et de ses constats ne se
lisent que par son **équipe** : l'auditeur et le responsable de la fiche, les membres ajoutés dans
l'onglet **Équipe** (auditeurs, ou audités en lecture seule) et le **groupe RSSI** choisi dans la
configuration du plugin. Le droit Documents de GLPI ne suffit pas. L'onglet **Accès aux documents**
liste chaque téléchargement (date, utilisateur, document) et l'onglet **Notes** garde les notes de
travail datées, avec leur auteur.

**Audit interne selon l'ISO 19011** (ISO 27001 9.2) :

1. **Programme pluriannuel** : la page **Programme d'audit pluriannuel** de la rubrique présente les
   processus du SMSI (les activités de la matrice des responsabilités) par année du cycle de
   certification (3 ans, année de départ choisie sur la page). Un processus en rouge n'a aucun audit
   sur le cycle, en orange son audit est en retard. Les exigences du SMSI qu'aucune grille d'audit du
   cycle ne couvre sont aussi signalées.
2. **Processus audités** : dans l'onglet **Programme et indépendance** de l'audit, choisissez les
   processus qu'il audite.
3. **Indépendance** : un auditeur (celui de la fiche ou un auditeur de l'équipe) qui réalise ou
   approuve un processus audité dans la matrice des responsabilités, en son nom ou par un groupe, est
   en conflit. L'audit ne peut être ni démarré ni terminé tant que chaque conflit n'a pas de
   justification, conservée avec son auteur, sa date et dans l'historique de l'audit.
4. **Compétence** : choisissez sur la page du programme les formations qui qualifient un auditeur
   interne (par exemple « Auditeur interne ISO 27001 »). L'onglet **Équipe** indique alors pour
   chaque auditeur s'il est qualifié, si sa qualification est à renouveler ou s'il n'en a aucune.
5. **Remise du rapport à la direction** : saisissez la date de remise et le destinataire dans le même
   onglet. Ils figurent dans le dossier de revue de direction (9.3.2 d 3).
6. **Objectifs et critères** : sur la fiche de l'audit, renseignez ses **Objectifs** (ce que l'audit
   doit établir) et ses **Critères d'audit** (articles de la norme, politiques, procédures auxquels
   les preuves sont comparées), à côté du périmètre.
7. **Points de contrôle** : l'encadré **Points de contrôle de l'audit**, en tête de l'onglet
   **Programme et indépendance**, indique en couleur ce que l'auditeur de certification vérifiera :
   objectifs, périmètre et critères définis, processus choisis, auditeurs désignés, qualifiés et
   indépendants, rapport remis à la direction. La page du programme reprend ces points pour chaque
   audit du cycle et signale les audits incomplets. Le rapport PDF de l'audit les reprend aussi, avec
   la compétence des auditeurs et la justification de chaque conflit.
8. **Indépendance par les mesures auditées** : un processus dont la matrice des responsabilités porte
   une mesure de l'Annexe A auditée (ligne « mesure » de la grille de l'audit, ou mesure liée sur la
   fiche) est contrôlé aussi, même s'il n'est pas choisi dans l'onglet. Le conflit indique les
   mesures concernées (« Par les mesures auditées A.8.13 ») et se justifie de la même façon.
9. **Planifier depuis le programme** : sur la page du programme, le bouton **Planifier** d'une case
   sans audit à venir (cette année ou une année suivante) crée un audit interne pour ce processus et
   cette année, dans l'entité du processus, et ouvre sa fiche. Complétez ensuite ses objectifs, son
   périmètre, ses critères et ses auditeurs.

Depuis l'onglet **Non-conformités** de la rubrique, une non-conformité peut être liée à cet audit interne, avec
une sévérité, une échéance, une description et une cause racine.

![Liste des non-conformités vide](screenshots/tutorial/20-nonconformity-list-empty.png)

![Formulaire d'une non-conformité liée à l'audit, statut ouverte](screenshots/tutorial/21-nonconformity-form-open.png)

Le cycle CAPA (clause 10.2 ISO/IEC 27001:2022) impose une action corrective avant de pouvoir
clôturer ou vérifier une non-conformité : tenter de passer au statut "Vérifiée" sans action
corrective renseignée bloque l'enregistrement.

![Passage au statut Vérifiée avant enregistrement, action corrective vide](screenshots/tutorial/22-nonconformity-closure-before-save.png)

![Erreur : action corrective obligatoire](screenshots/tutorial/23-nonconformity-closure-error.png)

Une fois l'action corrective (et, le cas échéant, l'action préventive) renseignée, la
non-conformité peut être vérifiée : la date de vérification de clôture est alors automatiquement
renseignée à la date du jour si elle est restée vide.

![Action corrective et préventive renseignées, statut Vérifiée](screenshots/tutorial/24-nonconformity-capa-filled.png)

![Non-conformité clôturée, date de vérification auto-renseignée](screenshots/tutorial/25-nonconformity-closed.png)

#### Audits de certification et constats de l'organisme

Un audit est **interne** (article 9.2) ou mené par l'**organisme de certification** : champ
**Type d'audit** (audit de certification initial, audit de surveillance 1 ou 2, audit de
renouvellement). Pour un audit de certification, la fiche demande l'organisme (un fournisseur GLPI,
**Gestion > Fournisseurs**), l'auditeur responsable et le référentiel avec son édition (par exemple
ISO/IEC 27001:2022). Les audits existants restent des audits internes.

Dans l'onglet **Constats** d'un audit de certification, chaque constat se saisit avec le
vocabulaire de l'organisme : **non-conformité majeure**, **non-conformité mineure**, **point
sensible** ou **piste de progrès**, avec la référence de l'organisme (par exemple GVE-36), le
processus audité, la clause ou la mesure et le site (un lieu GLPI). Le traitement est le même que
pour une non-conformité interne (cause, action corrective, responsable, échéance, preuves dans
l'onglet **Documents**, vérification) ; l'action corrective n'est obligatoire que pour une
non-conformité.

L'écran **Audits et amélioration > Constats des audits précédents** liste les constats de chaque
audit de certification avec leur état de levée (non levé, action en cours, action réalisée à
vérifier, levé) et leurs preuves. Il se filtre par audit ou sur les seuls constats non levés, et
s'exporte en PDF : c'est le document à remettre à l'auditeur à la réunion d'ouverture de l'audit
suivant. La **Préparation à la certification** rappelle, dans l'étape « Traiter les
non-conformités », le nombre de constats précédents non levés.

La liste des audits affiche la colonne **Type d'audit** juste après le titre.
