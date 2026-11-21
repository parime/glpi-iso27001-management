### Préparer l'audit de certification avec une date cible

1. Ouvrez Tableau de bord > **Préparation à la certification**. L'encadré « À quoi sert cette page ? »
   rappelle ce qui est attendu.
2. Donnez une **date cible** : soit dans le cadre « Réglages de la préparation à la certification »
   (par exemple le 3 décembre), soit en planifiant l'audit lui-même. Un audit dont le type est
   « Audit de certification initial », « Audit de surveillance 1 ou 2 » ou « Audit de renouvellement », ou un
   événement du calendrier de conformité de même nature, est repris automatiquement : rien à saisir
   deux fois.
3. Chaque étape non faite reçoit une **échéance conseillée**, calculée en remontant depuis la date
   cible (le socle d'abord, puis l'audit interne, la revue de direction et le traitement des
   non-conformités). Une étape dont l'échéance tombe dans les 14 jours est marquée « Risque de
   retard » ; une échéance dépassée est signalée en rouge. Ce délai d'alerte se règle dans le champ
   « Alerte (jours) », et le bouton « Délais conseillés par étape » ouvre le tableau du nombre de
   jours avant la date cible de chaque étape (par exemple audit interne 60 jours, revue de direction
   30 jours avant un audit le 15 mars). Un délai réglé à l'envers de l'ordre logique (revue de
   direction conseillée avant l'audit interne) est signalé, jamais corrigé à votre place.
4. Choisissez le **responsable de la préparation** (à défaut, le responsable de l'audit cible). La
   tâche automatique `certificationreadiness` lui envoie la notification « Étape de la certification
   à risque » quand une étape approche ou dépasse son échéance, une seule fois par étape et par état.
   Rien n'est modifié automatiquement. Il doit pouvoir lire le plugin sur toutes les entités, puisque
   l'état couvre tout le SMSI.
5. Les échéances conseillées figurent dans le **calendrier de conformité** (et dans le planning du
   responsable), avec un lien vers l'étape à compléter.
6. Réglez au besoin les **seuils** : couverture visée du programme d'audit (100 % par défaut, sur les
   trois dernières années) et part des participants formés (80 % par défaut).
7. Une fois certifié, saisissez la **date de certification** (ou terminez l'audit initial de
   certification) : le cadre « Cycle de maintien » affiche les deux audits de surveillance annuels et
   le renouvellement à trois ans, la date cible suit le prochain d'entre eux, et les étapes à refaire
   chaque année (appréciation des risques, SoA, audit interne, revue de direction) sont mises en
   avant. Le découpage des audits internes sur le cycle reste dans le **programme d'audit
   pluriannuel**.
8. Les réglages demandent le droit de modifier le plugin ; la page reste lisible avec le droit de
   lecture.
