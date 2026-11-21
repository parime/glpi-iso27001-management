### Recenser et contrôler les cartes de gestion des serveurs (mesures A.8.2, A.8.9 et A.8.22)

Les cartes de gestion à distance (HPE iLO, Dell iDRAC, Lenovo XClarity Controller, Supermicro
IPMI) donnent le contrôle complet d'un serveur, même éteint. Le plugin les lit **en lecture seule**
par Redfish : il n'envoie que des requêtes de lecture (GET), ne redémarre rien, ne met à jour aucun
firmware et ne change aucun réglage. Il faut le droit du plugin et le droit natif de lecture des
ordinateurs ; le droit de modifier le plugin pour déclarer, lire et importer.

1. Sur chaque contrôleur, créez un compte local avec le rôle **lecture seule** (ReadOnly), réservé
   au plugin.
2. Les contrôleurs ont presque toujours une adresse interne : dans la configuration du plugin,
   section **Connexions sortantes**, autorisez les adresses internes (la page le rappelle tant que ce
   n'est pas fait).
3. Ouvrez Sécurité technique > **Contrôleurs de gestion des serveurs**. Dans **Déclarer un
   contrôleur lu par Redfish**, saisissez son nom, son adresse `https://`, le compte et son mot de
   passe. Le mot de passe est chiffré par GLPI et n'est jamais réaffiché ; il faut le ressaisir si
   l'adresse ou le compte changent. Laissez **Serveur dans GLPI** vide pour rapprocher le serveur
   par son numéro de série.
4. Un contrôleur au certificat auto-signé est refusé tant que vous ne cochez pas **Accepter un
   certificat auto-signé pour ce contrôleur** : l'option est désactivée par défaut, vaut pour ce
   seul contrôleur, et l'écart « Certificat non vérifié » reste signalé.
5. Cliquez sur **Lire maintenant**. Le plugin lit le modèle, le firmware du contrôleur, le BIOS, le
   numéro de série et la santé du serveur, les protocoles ouverts, le certificat HTTPS, le démarrage
   sécurisé et les comptes locaux. Une lecture en échec ne remplace pas la précédente : la cause est
   affichée à côté de la dernière lecture réussie.
6. Pour un contrôleur sans Redfish (IPMI seul), importez un fichier CSV dans **Importer des
   contrôleurs sans Redfish**, une ligne par contrôleur, par exemple :
   `name;address;model;firmware;serial;ipmi_lan;weak_tls;accounts` puis
   `bmc-srv03;192.0.2.30;X11;3.10;SM123456;oui;non;ADMIN:Administrator`.
7. La liste **Contrôleurs et leurs écarts** commence par les écarts graves : IPMI sur le réseau,
   compte par défaut actif, adresse hors des réseaux d'administration, Telnet, protocoles anciens,
   certificat expiré. Survolez un écart pour savoir quoi faire, puis corrigez-le sur le contrôleur :
   le plugin ne fait que le signaler.
8. L'exposition se vérifie par rapport à la matrice des flux : écrivez la plage du réseau
   d'administration dans la source ou la destination d'un flux dont le nom désigne une zone
   d'administration, par exemple `MGMT 10.0.99.0/24`.
9. **Comptes locaux des contrôleurs** liste les comptes et leur rôle, à reprendre dans la revue des
   accès privilégiés. **Serveurs physiques sans contrôleur connu** liste les serveurs de GLPI (par
   leur type ou leur système, hors machines virtuelles) dont personne ne suit la carte de gestion.
10. Sur la fiche d'un ordinateur, l'onglet **Contrôleur de gestion** résume son contrôleur. Sur les
    fiches des mesures A.8.2, A.8.9 et A.8.22, le cadre « Contrôleurs de gestion des serveurs »
    donne les chiffres de la preuve, et les indicateurs de risque proposent la source « Contrôleurs
    de gestion des serveurs avec un écart grave ».
