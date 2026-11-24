### Relire les correspondances de fin de support proposées

Les logiciels, systèmes et firmwares dont le nom n'est pas reconnu ne sont pas suivis par
endoflife.date. Plutôt que de chercher chaque produit à la main, GRC en propose un.

1. Ouvrez **Parc > Sécurité du parc > Fin de vie et correctifs > Logiciels à surveiller**, bloc
   **Fin de support (endoflife.date)**.
2. Si le catalogue n'a jamais été lu, cliquez sur **Mettre à jour maintenant** (droit de modifier la
   configuration du plugin). Il est ensuite relu une fois par semaine par la tâche quotidienne.
3. Le cadre **Correspondances proposées, à relire** liste chaque nom non relié avec le produit
   proposé et la raison : nom ou alias du produit, identifiant CPE ou identifiant de paquet (purl).
   Quand deux ou trois produits conviennent, choisissez le bon dans la liste.
4. Cochez les propositions justes, puis **Accepter les propositions cochées** : elles deviennent des
   correspondances manuelles, visibles dans le tableau en dessous et modifiables comme les autres.
5. Cochez les propositions fausses, puis **Refuser les propositions cochées** : elles ne sont plus
   proposées pour ce nom.

Rien n'est appliqué sans votre validation, et un nom approchant n'est jamais proposé : seule
l'égalité exacte compte (version et précisions entre parenthèses mises à part).
