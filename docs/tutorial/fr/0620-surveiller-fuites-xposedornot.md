### Surveiller les fuites de données des adresses professionnelles sans abonnement

La surveillance des fuites cherche chaque jour les adresses professionnelles de vos utilisateurs
dans les fuites de données connues (mesure A.5.7, renseignement sur les menaces). Elle fonctionne
avec **XposedOrNot**, service gratuit, ou avec **Have I Been Pwned**, dont la clé est payante.

1. Ouvrez **Configuration > Plugins > GRC Manager**, onglet **Connecteurs**, bloc
   **Surveillance des fuites de données** (il faut le droit de configurer le plugin et le droit des
   registres sensibles).
2. Saisissez les **domaines professionnels** à surveiller (par exemple `example.com`) : seules les
   adresses de ces domaines sont envoyées au fournisseur, jamais une adresse personnelle.
3. Choisissez **XposedOrNot** (badge « Gratuit, sans clé »), cochez **Activer la surveillance**,
   puis **Enregistrer**. Le bouton **Tester la connexion** vérifie que le service répond, avec
   l'adresse d'exemple de sa documentation : aucune adresse réelle n'est envoyée.
4. Sans clé, XposedOrNot vérifie 20 adresses au plus par passage (le service en accepte 25 par heure
   et 100 par jour) ; le passage suivant reprend là où le précédent s'est arrêté. Pour couvrir plus
   vite un grand nombre d'utilisateurs, réglez l'action automatique **breachmonitor**
   (Configuration > Actions automatiques) pour qu'elle s'exécute toutes les heures.
5. Si vous avez vérifié votre domaine sur le tableau de bord de XposedOrNot, collez la **clé d'API de
   domaine** qu'il vous remet : tout le domaine est alors vérifié en une seule requête. La clé est
   chiffrée par GLPI et n'est jamais réaffichée.
6. Les expositions trouvées apparaissent dans **GRC > Personnel et sensibilisation > Expositions à
   des fuites de données** et le groupe RSSI est prévenu. Pour chacune, faites changer le mot de passe si des
   mots de passe ont fuité, puis passez-la à **Prise en compte (mot de passe changé…)**.

Les adresses vérifiées sont envoyées à un tiers (le fournisseur choisi) : mentionnez-le dans votre
registre des traitements.
