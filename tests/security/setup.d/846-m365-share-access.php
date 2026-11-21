<?php

/**
 * Issue #846, lot 2 : droits SharePoint, OneDrive et Teams. Fragment inclus par
 * tests/security/setup.php (jeu de données).
 */

// Site SharePoint importé de chaque entité, avec un invité externe et un lien public.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $siteRow = [
        'entities_id' => $entity, 'source' => 'sharepoint', 'server' => 'securite.onmicrosoft.com',
        'name' => 'Site securite ' . $suffix,
    ];
    $shareTable = \GlpiPlugin\Grcmanager\Install\ShareAccessTables::SHARES;
    $aceTable = \GlpiPlugin\Grcmanager\Install\ShareAccessTables::ACES;
    if (countElementsInTable($shareTable, $siteRow) === 0) {
        $now = date('Y-m-d H:i:s');
        $DB->insert($shareTable, $siteRow + [
            'share_path' => 'https://securite.sharepoint.com/sites/site' . $suffix, 'is_present' => 1,
            'date_import' => $now, 'date_creation' => $now,
        ]);
        $siteId = (int) $DB->insertId();
        $DB->insert($aceTable, [
            'plugin_grcmanager_fileshares_id' => $siteId, 'fingerprint' => sha1('m365' . $suffix),
            'scope' => 'link', 'folder' => 'https://securite.sharepoint.com/sites/site' . $suffix . '/Contrats',
            'depth' => 1, 'is_protected' => 1, 'identity' => 'invite.site' . $suffix . '@partenaire.test',
            'kind' => 'external', 'rights' => 'write (edit link)', 'level' => 'modify', 'status' => 'current',
            'date_first_seen' => $now,
        ]);
        $DB->insert($aceTable, [
            'plugin_grcmanager_fileshares_id' => $siteId, 'fingerprint' => sha1('m365anon' . $suffix),
            'scope' => 'link', 'folder' => 'https://securite.sharepoint.com/sites/site' . $suffix,
            'identity' => 'Anyone with the link', 'sid' => 'link:anonymous', 'kind' => 'anonymous',
            'rights' => 'read (view link)', 'level' => 'read', 'status' => 'current', 'date_first_seen' => $now,
        ]);
    }
    $ids['m365_share_' . $suffix] = 0;
    foreach ($DB->request(['FROM' => $shareTable, 'WHERE' => $siteRow]) as $row) {
        $ids['m365_share_' . $suffix] = (int) $row['id'];
    }
}
