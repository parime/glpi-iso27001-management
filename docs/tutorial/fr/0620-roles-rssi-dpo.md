### Désigner le RSSI et le DPO (rôles du SMSI)

La mesure A.5.2 demande que les rôles de la sécurité de l'information soient définis et attribués.
Le plugin connaît deux rôles clés, le **RSSI** et le **DPO** ; pour chacun, il garde un groupe GLPI
et une adresse e-mail générique.

1. Créez dans GLPI (**Administration > Groupes**) un groupe par rôle, par exemple « RSSI » et
   « DPO », et ajoutez-y le titulaire et son suppléant. Laissez **Peut être notifié** à Oui sur le
   groupe (valeur par défaut) pour qu'il reçoive les e-mails.
2. Ouvrez **Configuration > Plugins > GRC Manager**, onglet **Personnes et politiques**, bloc
   **Rôles du SMSI**. Pour chaque rôle, choisissez le groupe et saisissez l'adresse générique
   (une boîte partagée ou une liste, par exemple `dpo@example.org`, jamais une adresse
   personnelle), puis **Sauvegarder**. Une adresse au format invalide est refusée.
3. Si vous aviez saisi le DPO dans l'ancien champ texte, la mise à jour l'a repris : l'adresse
   trouvée est devenue l'adresse générique, le reste (nom, téléphone) apparaît dans une note
   **À reprendre**. Choisissez le groupe, puis cochez **La note a été reprise : la supprimer**.
4. Vérifiez le résultat dans **GRC & Conformité > Organisation du SMSI > Matrice des
   responsabilités** : les deux rôles, les membres actifs de leur groupe et leurs adresses sont en
   tête de la page et dans le PDF de la matrice, à présenter à l'auditeur.

Le groupe RSSI lit les documents de tous les audits internes ; le registre des traitements et le
registre des violations affichent le groupe DPO, ses membres et l'adresse générique. Pour la
notification **Nouvelles expositions à des fuites de données** (**Configuration >
Notifications**), ajoutez si besoin les destinataires **Groupe DPO (GRC Manager)**, **Adresse
générique du DPO (GRC Manager)** ou **Adresse générique du RSSI (GRC Manager)**.
