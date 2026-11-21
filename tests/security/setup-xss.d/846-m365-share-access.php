<?php

/**
 * Issue #846, lot 2 : droits SharePoint, OneDrive et Teams. Fragment inclus par
 * tests/security/setup.php xss (données piégées).
 */

// Adresse, propriétaire Microsoft 365, compte et droit piégés (liste et fiche d'un site).
$DB->update(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::SHARES, [
    'share_path' => $canary, 'owner' => mb_substr($canary, 0, 255), 'comment' => $canary,
], ['id' => (int) $ids['m365_share_A']]);
$DB->update(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::ACES, [
    'identity' => mb_substr($canary, 0, 255), 'rights' => mb_substr($canary, 0, 255), 'folder' => $canary,
], ['plugin_grcmanager_fileshares_id' => (int) $ids['m365_share_A']]);
