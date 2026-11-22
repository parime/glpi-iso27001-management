### Fixed

- **Badges orange, bleus, verts... sans fond** (issue #1026). GLPI et Tabler ne définissent les
  classes `bg-X-subtle` et `text-X-emphasis` que pour les couleurs de thème (primary, success,
  warning, danger...) : les badges GRC de couleur nommée (orange pour une gravité haute ou une
  échéance proche, mais aussi blue, azure, green, red, purple, yellow, teal, lime, indigo, cyan)
  s'affichaient sans fond, sauf si le plugin Docker Inventory était actif. GRC charge désormais sa
  propre feuille de style (`public/css/grcmanager.css`, hook `ADD_CSS`) qui définit ces classes pour
  les douze couleurs nommées de Tabler, en thème clair et en thème sombre (contraste du texte d'au
  moins 7,5:1). L'orange reprend la teinte de Docker Inventory. Un test unitaire échoue si le code
  utilise une classe de ce type que ni Tabler ni cette feuille ne définissent.
