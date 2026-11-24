### Cartographier le SI par couches, du switch au processus métier

La cartographie relie ce que GLPI et GRC connaissent déjà, sans rien dessiner à la main :

1. Ouvrez **Analyses de risques > Cartographie du SI**.
2. Sans rien choisir, la page propose deux points de départ : vos **processus métier** (« De quoi
   dépend ce processus ? ») et vos **matériels réseau** (« Que touche la panne de cet
   équipement ? »). Cliquez sur l'un d'eux, ou choisissez un autre élément de départ (ordinateur,
   logiciel, application, instance de base de données) et la question, puis **Afficher la carte**.
3. Vers le haut, la page liste d'abord les **processus métier touchés, du plus urgent au moins
   urgent** (RTO du BIA), puis le schéma : une bande par couche, les processus en haut et le réseau
   en bas. Chaque case est un lien vers la fiche de l'élément ; la liste par couche sous le schéma
   reprend tous les éléments.
4. Un lien manque ? Complétez-le là où il se décrit : câble entre deux ports (fiche du matériel,
   onglet **Ports réseau**), machine virtuelle (même UUID que l'ordinateur), logiciel installé,
   élément d'une application, dépendance d'un processus (onglet **Dépendances** du processus) ou
   analyse d'impact de GLPI.

Deux switchs reliés entre eux ne propagent pas l'impact (le plugin ne connaît pas la redondance) :
déclarez la dépendance dans l'analyse d'impact de GLPI si elle existe. Un élément que vous ne pouvez
pas voir (autre entité, droit manquant) est compté sans être nommé.

Pour aller plus loin :

- cliquez sur une case du schéma pour **repartir de cet élément** ; décochez des **couches** pour
  alléger la carte ;
- cochez **Flux applicatifs (matrice des flux)** pour ajouter les flux entre applications et serveurs
  (traits pointillés) : le client dépend du serveur qu'il appelle ;
- **Export (CSV)** et **Export (PDF)** donnent la carte à joindre au PCA/PRA ou au dossier d'audit ;
- la même carte est dans l'onglet **Cartographie du SI** de chaque ordinateur, matériel réseau,
  application et processus métier ;
- la bande **VLAN et réseaux IP** montre le réseau logique, lu dans GLPI : VLAN des ports réseau,
  réseaux IP des adresses, champ **Réseau** des matériels et des éléments d'une application. Un VLAN
  ou un réseau IP peut aussi servir de point de départ (« Que touche la panne de ce VLAN ? ») ;
- renseignez l'**environnement** de chaque application dans GLPI (Parc > Applicatifs : production,
  recette, préproduction) avec ses serveurs et instances (onglet **Éléments**) : la carte les range
  dans le tableau **Applications par environnement**, et le choix **Environnement des applications**
  ne garde que les applications de cet environnement, par exemple pour l'impact d'une panne sur la
  production seule.
