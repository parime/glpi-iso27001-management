### Rapprocher les systèmes d'exploitation des CVE (mesure A.8.8)

Les vulnérabilités du parc suivent les logiciels installés, les firmwares et les **systèmes
d'exploitation** (onglet « Systèmes d'exploitation » d'un ordinateur dans GLPI : nom, version,
version du noyau). Un système n'est rapproché d'une CVE qu'après votre validation. Il faut le droit
« Registres sensibles » du plugin en modification et le droit de lecture des logiciels.

1. Ouvrez Sécurité technique > **Logiciels à surveiller**. Les systèmes d'exploitation du parc y
   apparaissent avec un badge **Système d'exploitation**, par exemple « Microsoft Windows 11
   Professionnel 23H2 » ou « Debian GNU/Linux 12 (bookworm) ». Pour Windows 10 et 11, la version
   de fonctionnalités (23H2) fait partie du nom, comme dans la base NVD.
2. Pour Windows 10 et 11, Windows Server 2016 à 2025, Debian, Ubuntu, Red Hat Enterprise Linux,
   Rocky Linux, AlmaLinux, Oracle Linux, Fedora et macOS, la colonne « Produit surveillé » affiche une **Correspondance proposée**, par exemple
   `cpe:2.3:o:microsoft:windows_11_23h2`. Vérifiez-la, puis cliquez sur **Accepter la
   proposition**. Pour un autre système, cliquez sur **Chercher le CPE** comme pour un logiciel.
3. Ouvrez **Vulnérabilités du parc** : les CVE du système portent le badge « Système
   d'exploitation ». Le tableau, l'onglet « Vulnérabilités » de l'ordinateur et l'onglet CVE d'un
   incident de sécurité nomment seulement les actifs de vos entités.
4. Les versions sont comparées comme dans NVD : numéro de build pour Windows (version du noyau),
   version majeure pour Debian, RHEL, Oracle Linux et Fedora, « 22.04 » pour Ubuntu, « 9.3 » pour
   Rocky Linux et AlmaLinux, version complète pour macOS. Si l'inventaire ne donne pas la
   révision du build Windows (« 10.0.22631 » au lieu de « 10.0.22631.4317 »), l'ordinateur reste
   « version non vérifiable » : il n'est ni déclaré vulnérable ni déclaré corrigé.
5. Les tickets de mise à jour lient les ordinateurs concernés de l'entité du ticket, y compris
   ceux qui le deviennent après l'ouverture du ticket.
6. La page **Machines les plus exposées** (Sécurité technique) compte aussi les
   vulnérabilités du système d'exploitation et des firmwares de chaque machine : un serveur à jour
   de ses logiciels mais sous un système vulnérable y remonte.
