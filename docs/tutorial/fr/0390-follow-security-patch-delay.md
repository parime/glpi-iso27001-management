### Suivre le retard des correctifs de sécurité (mesures A.8.8 et A.8.19)

L'agent d'inventaire de GLPI remonte le système de chaque ordinateur, sa version et, selon la
plateforme, les mises à jour installées (articles KB de Windows) ou les paquets installés avec leur
date. Le plugin lit ces données ; il ne lance aucune mise à jour et ne modifie rien dans GLPI. Il
faut le droit du plugin et le droit natif de lecture des ordinateurs.

1. Ouvrez Sécurité technique > **Correctifs de sécurité**. L'encadré « À quoi sert cette page ? »
   rappelle ce qui est attendu.
2. Dans **Délais cibles par criticité**, réglez le nombre de jours admis depuis le dernier correctif
   pour les ordinateurs critiques, importants et standard (15, 30 et 60 jours par défaut). La
   criticité vient de la classification C/I/D de l'ordinateur ; un ordinateur publié sur Internet par
   un flux réseau déclaré est critique. Ces délais se règlent dans Configuration > Plugins > GRC
   Manager, onglet **Parc** (bouton **Modifier dans la configuration**) ; il faut le droit de modifier
   le plugin.
3. Les compteurs donnent le taux d'ordinateurs dans les délais, le nombre en retard et d'état
   inconnu (aucune mise à jour datée : pas d'agent, ou un système dont l'agent ne remonte pas les
   mises à jour), les serveurs critiques et les ordinateurs publiés sur Internet en retard.
4. La liste commence par les retards, les ordinateurs exposés sur Internet et critiques d'abord.
   Pour chacun : système et version, date du dernier correctif connu et son origine, délai cible,
   jours de retard, CVE connues du système et fin de support, date du dernier inventaire (badge
   « Inventaire ancien » au-delà de 30 jours). Appliquez les correctifs, puis attendez le prochain
   inventaire.
5. Sur la fiche des mesures A.8.8 et A.8.19, le cadre « Correctifs de sécurité » montre le taux, les
   retards et la date du calcul. Le tableau de bord propose la carte « Ordinateurs aux correctifs
   dans les délais (%) » et les indicateurs de risque la source « Serveurs critiques avec
   correctifs en retard ».
