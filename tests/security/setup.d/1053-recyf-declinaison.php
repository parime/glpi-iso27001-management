<?php

/**
 * Issue #1053 : ReCyF, déclinaison France de NIS2, a désormais son propre réglage. Fragment inclus
 * par tests/security/setup.php (jeu de données) : la déclinaison est cochée pour que le registre
 * ReCyF reste couvert par la matrice d'accès et le contrôle XSS.
 */

\GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('nis2', true);
\GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig::setEnabled('recyf', true);
