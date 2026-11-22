### Trouver la sécurité du parc dans le menu Parc

Les écrans de sécurité technique sont rangés dans **Parc > Sécurité du parc**, à côté des
ordinateurs et des matériels réseau.

1. Ouvrez **Parc > Sécurité du parc**. La page d'accueil range les écrans en cartes, par thème :
   - **Vulnérabilités** : vulnérabilités du parc, machines les plus exposées, produits suivis, comptes
     exposés dans des fuites de données ;
   - **Fin de vie et correctifs** : logiciels à surveiller (fin de support lue sur endoflife.date),
     fin de vie des matériels, retard des correctifs, licences ;
   - **Durcissement** : contrôles techniques automatiques, conformité de configuration, chiffrement
     des disques, audit de l'Active Directory ;
   - **Supervision** : journalisation, Zabbix, Uptime Kuma, contrôleurs de serveurs ;
   - **Sauvegardes et réseau** : sauvegardes, messagerie et domaines, matrice des flux ;
   - **Sites et développement** : sécurité physique, développement sécurisé, sécurité du code.
2. La barre d'onglets en haut de chaque page montre un onglet par thème ; la seconde barre montre les
   pages du thème ouvert. Le fil d'Ariane indique où vous êtes : « Parc > Sécurité du parc > … ».
3. Pour la **fin de vie annoncée par le constructeur** d'un modèle, ouvrez la fiche du modèle
   (par exemple Configuration > Intitulés > Modèles d'ordinateurs), onglet **Fin de vie
   constructeur**, et saisissez la date. Pour dater tous les modèles en service d'un coup : **Parc >
   Sécurité du parc > Fin de vie des matériels**. Le seuil d'alerte (« fin de vie proche ») est celui
   de la fin de support, dans la configuration du plugin, onglet Connecteurs.
4. Les boutons d'action ont quitté la configuration : **Mettre à jour maintenant** (endoflife.date)
   et les correspondances entre un nom de GLPI et un produit sont sur **Logiciels à surveiller** ;
   **Envoyer le rapport maintenant** est sur **Vulnérabilités du parc**.

Le nouveau menu apparaît à la connexion suivante.
