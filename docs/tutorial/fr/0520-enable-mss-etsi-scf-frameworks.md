### Suivre MonServiceSécurisé, ETSI EN 319 401 ou le Secure Controls Framework

Trois référentiels complémentaires sont proposés, désactivés par défaut, et reliés à l'Annexe A :

- **MonServiceSécurisé (ANSSI)**, pour une administration ou un prestataire qui homologue un service
  numérique avec le service de l'ANSSI : ses 135 mesures, rangées en 29 thèmes ;
- **ETSI EN 319 401**, pour un prestataire de services de confiance (signature, cachet, horodatage,
  certificats) audité au titre d'eIDAS : les paragraphes 5 à 7 de la norme ;
- **Secure Controls Framework (SCF)**, pour une organisation qui se sert du SCF comme cadre commun :
  ses 34 domaines.

1. Dans la configuration du plugin, cadre **Référentiels de conformité**, cochez le référentiel
   voulu puis cliquez sur **Sauvegarder**.
2. Ouvrez la page **Référentiels** : une nouvelle carte liste les thèmes, paragraphes ou domaines,
   avec les mesures de l'Annexe A reliées (cliquables) et les modules du plugin qui produisent déjà
   des preuves. Le champ **Filtrer** retrouve vite un identifiant (`AUTH`, `7.14.3`, `VPM`...).
3. Sur la fiche d'une mesure de l'Annexe A, un badge (« MSS AUTH », « EN 319 401 7.4.5 »,
   « SCF IAC ») indique ce qu'elle couvre ; le taux du référentiel apparaît dans le tableau de bord
   et dans l'évaluation par référentiel.

La correspondance avec l'Annexe A est indicative, établie par le projet : relisez-la avec votre
auditeur. Le pied de chaque carte donne la source et la licence (Apache 2.0 pour MonServiceSécurisé,
norme protégée dont seuls les numéros sont repris pour l'ETSI, CC BY-ND 4.0 pour le SCF, dont les
noms de domaines restent en anglais).
