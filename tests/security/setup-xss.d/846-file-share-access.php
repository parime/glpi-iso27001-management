<?php

/**
 * Issue #846 : droits sur les partages de fichiers. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

use GlpiPlugin\Grcmanager\Install\ShareAccessTables;

// Issue #846 : partage, chemin, compte, droit et commentaire de revue piégés (liste et fiche).
$DB->update(ShareAccessTables::SHARES, [
    'share_path' => $canary, 'owner' => mb_substr($canary, 0, 255), 'comment' => $canary,
], ['id' => (int) $ids['share_A']]);
$DB->update(ShareAccessTables::ACES, [
    'identity' => mb_substr($canary, 0, 255), 'rights' => mb_substr($canary, 0, 255), 'folder' => $canary,
    'folder_owner' => mb_substr($canary, 0, 255), 'decision_comment' => $canary,
], ['plugin_grcmanager_fileshares_id' => (int) $ids['share_A']]);
