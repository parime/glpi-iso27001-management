<?php

/**
 * Issue #846 : droits sur les partages de fichiers. Fragment inclus par tests/security/setup.php
 * (jeu de données).
 */

// Issue #846 : partage importé de chaque entité, en cours de revue, avec un droit nominatif.
foreach (['A' => $a, 'B' => $b] as $suffix => $entity) {
    $shareRow = [
        'entities_id' => $entity, 'source' => 'smb', 'server' => 'fs-securite.exemple.test',
        'name' => 'Partage securite ' . $suffix,
    ];
    if (countElementsInTable(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::SHARES, $shareRow) === 0) {
        $now = date('Y-m-d H:i:s');
        $DB->insert(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::SHARES, $shareRow + [
            'share_path' => 'D:\\Partages\\Securite' . $suffix, 'is_present' => 1, 'review_status' => 'open',
            'confidentiality' => 'high', 'date_import' => $now, 'date_creation' => $now,
        ]);
        $DB->insert(\GlpiPlugin\Grcmanager\Install\ShareAccessTables::ACES, [
            'plugin_grcmanager_fileshares_id' => (int) $DB->insertId(), 'fingerprint' => sha1('securite' . $suffix),
            'scope' => 'ntfs', 'folder' => '\\\\fs-securite.exemple.test\\Partage securite ' . $suffix,
            'identity' => 'EXEMPLE\\Compte partage securite ' . $suffix, 'kind' => 'user', 'rights' => 'Modify',
            'level' => 'modify', 'status' => 'current', 'decision' => 'pending',
            'decision_comment' => 'Decision partage securite ' . $suffix, 'date_first_seen' => $now,
        ]);
    }
    $ids['share_' . $suffix] = 0;
    $shareTable = \GlpiPlugin\Grcmanager\Install\ShareAccessTables::SHARES;
    foreach ($DB->request(['FROM' => $shareTable, 'WHERE' => $shareRow]) as $row) {
        $ids['share_' . $suffix] = (int) $row['id'];
    }
    $ids['share_ace_' . $suffix] = 0;
    foreach (
        $DB->request([
            'FROM'  => \GlpiPlugin\Grcmanager\Install\ShareAccessTables::ACES,
            'WHERE' => ['plugin_grcmanager_fileshares_id' => $ids['share_' . $suffix]],
        ]) as $row
    ) {
        $ids['share_ace_' . $suffix] = (int) $row['id'];
    }
}
