### Repérer les protocoles TLS anciens des contrôleurs de serveurs (mesures A.8.9 et A.8.24)

Chaque lecture Redfish d'un contrôleur ({{step:0440-review-server-management-controllers}}) cherche
aussi les versions de TLS acceptées par son interface HTTPS. Rien n'est modifié : uniquement des
requêtes de lecture (GET) vers l'adresse configurée.

1. Cliquez sur **Lire maintenant** sur la page **Contrôleurs de gestion des serveurs**.
2. L'écart **Protocoles anciens (SSLv3, TLS 1.0 ou 1.1)** apparaît si le contrôleur en accepte
   encore un. Selon le constructeur, le réglage vient du service de sécurité de l'iLO (HPE), de
   l'attribut `WebServer.1.TLSProtocol` de l'iDRAC (Dell), de la rubrique Oem du XClarity
   Controller (Lenovo) ou d'un autre contrôleur, ou de la politique de sécurité Redfish standard.
3. Le compte de lecture doit pouvoir lire ces réglages. S'il ne le peut pas, ou si le firmware ne les
   publie pas, la valeur reste **inconnue** : renseignez alors la colonne `weak_tls` de l'import CSV.
4. Corrigez sur le contrôleur lui-même (TLS 1.2 et plus seulement), puis relisez-le : l'écart
   disparaît.
