<?php

/**
 * Issue #846 : droits sur les partages de fichiers. Fragment inclus par tests/security/setup.php xss
 * (données piégées).
 */

// Issue #846 : partage, chemin, compte, droit et commentaire de revue piégés (liste et fiche).
$DB->update(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::SHARES, [
    'share_path' => $canary, 'owner' => mb_substr($canary, 0, 255), 'comment' => $canary,
], ['id' => (int) $ids['share_A']]);
$DB->update(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::ACES, [
    'identity' => mb_substr($canary, 0, 255), 'rights' => mb_substr($canary, 0, 255), 'folder' => $canary,
    'folder_owner' => mb_substr($canary, 0, 255), 'decision_comment' => $canary,
], ['plugin_grcmanager_fileshares_id' => (int) $ids['share_A']]);
