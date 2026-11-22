### Vérifier la sécurité de la messagerie et des domaines (mesures A.5.14 et A.8.21)

Un domaine mal protégé permet d'envoyer des e-mails qui semblent venir de l'organisation
(usurpation, hameçonnage). L'onglet **Sécurité de la messagerie et des domaines** de la rubrique
**Sécurité technique** montre, domaine par domaine, les protections publiées dans le DNS. Le plugin
lit seulement des enregistrements DNS publics : il n'envoie aucun e-mail et ne se connecte à aucun
serveur de messagerie. Tant qu'aucun domaine n'est saisi, il ne fait aucune requête.

1. Dans **Ajouter un domaine**, saisissez le nom (la partie après le @ des adresses, par exemple
   `example.org`), les **sélecteurs DKIM** donnés par votre service de messagerie (`selector1`,
   `selector2` pour Microsoft 365, `google` pour Google Workspace) et les **dates d'échéance** du
   domaine (espace client du bureau d'enregistrement) et, si vos serveurs de messagerie sont chez
   vous, de leur certificat. Ajoutez aussi les domaines que l'organisation possède sans s'en servir :
   ils doivent annoncer qu'ils n'envoient rien.
2. Cliquez sur **Vérifier maintenant** (ou **Vérifier tous les domaines**). Chaque colonne prend une
   couleur : vert pour une protection en place, orange pour une protection à renforcer, rouge pour
   un écart à corriger, gris pour ce qui n'est pas encore lu ou pas saisi.
3. Le cadre **Que faire ?** liste les écarts, les plus graves d'abord, avec un conseil en clair :
   DMARC absent ou en « none » (les e-mails usurpés sont toujours remis), SPF qui autorise tout le
   monde (`+all`), domaine non signé par DNSSEC, clé DKIM introuvable, domaine ou certificat qui
   expire dans moins de 30 jours. Les corrections se font chez votre hébergeur DNS : le plugin ne
   modifie rien.
4. Les fiches des mesures **A.5.14** et **A.8.21** reprennent la synthèse en preuve ; la source de
   KRI **Domaines de messagerie sans politique DMARC appliquée (%)** permet de suivre la progression.

Si la colonne DNSSEC reste « Inconnu », le serveur DNS du serveur GLPI ne relaie pas les
enregistrements DNSSEC (c'est le cas dans Docker) : saisissez l'adresse IP d'un autre serveur DNS de
l'organisation ou du fournisseur dans le cadre **Serveur DNS utilisé pour DNSSEC**, puis vérifiez de
nouveau.
