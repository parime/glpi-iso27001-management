<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Access;

use GlpiPlugin\Grcmanager\Services\Access\ShareAccessRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #846 : droits sur les partages de fichiers, logique pure sur des exports fictifs.
 */
final class ShareAccessRulesTest extends TestCase
{
    public function testRightLevels(): void
    {
        foreach (['FullControl', 'Full', '268435456', '2032127', 'Contrôle total'] as $full) {
            $this->assertSame(R::LEVEL_FULL, R::rightLevel($full), $full);
        }
        foreach (['Modify, Synchronize', 'Change', 'Write', 'AppendData', '1073741824', 'Modification'] as $modify) {
            $this->assertSame(R::LEVEL_MODIFY, R::rightLevel($modify), $modify);
        }
        $reads = ['Read', 'ReadAndExecute, Synchronize', 'ListDirectory', '-1610612736', 'Lecture et exécution'];
        foreach ($reads as $read) {
            $this->assertSame(R::LEVEL_READ, R::rightLevel($read), $read);
        }
        $this->assertSame(R::LEVEL_OTHER, R::rightLevel(''));
        $this->assertSame(R::LEVEL_OTHER, R::rightLevel('1048576'));
    }

    public function testPrincipalKinds(): void
    {
        $this->assertSame(R::KIND_BROAD, R::principalKind('Everyone', 'S-1-1-0'));
        $this->assertSame(R::KIND_BROAD, R::principalKind('Tout le monde'));
        $this->assertSame(R::KIND_BROAD, R::principalKind('AUTORITE NT\\Utilisateurs authentifiés'));
        $this->assertSame(R::KIND_BROAD, R::principalKind('EXEMPLE\\Domain Users', 'S-1-5-21-1-2-3-513'));
        // Le SID prime sur le nom (Windows dans une autre langue).
        $this->assertSame(R::KIND_BROAD, R::principalKind('EXEMPLE\\Utenti di dominio', 'S-1-5-21-1-2-3-513'));
        $this->assertSame(R::KIND_SYSTEM, R::principalKind('NT AUTHORITY\\SYSTEM', 'S-1-5-18'));
        $this->assertSame(R::KIND_SYSTEM, R::principalKind('BUILTIN\\Administrateurs'));
        $this->assertSame(R::KIND_SYSTEM, R::principalKind('EXEMPLE\\Admins du domaine', 'S-1-5-21-1-2-3-512'));
        $this->assertSame(R::KIND_ORPHAN, R::principalKind('S-1-5-21-1111-2222-3333-1188'));
        $this->assertSame(R::KIND_USER, R::principalKind('EXEMPLE\\alice.martin', 'S-1-5-21-1-2-3-1105', 'user'));
        $this->assertSame(R::KIND_GROUP, R::principalKind('EXEMPLE\\GG_Compta', '', 'Group'));
        $this->assertSame(R::KIND_UNKNOWN, R::principalKind('EXEMPLE\\GG_Compta'));
    }

    public function testShortNameAndUnc(): void
    {
        $this->assertSame('alice.martin', R::shortName('EXEMPLE\\Alice.Martin'));
        $this->assertSame('alice.martin', R::shortName('alice.martin@exemple.test'));
        $this->assertSame(['server' => 'fs01', 'share' => 'Compta'], R::splitUnc('\\\\fs01\\Compta\\Paie'));
        $this->assertSame(['server' => '', 'share' => ''], R::splitUnc('D:\\Partages'));
    }

    public function testParseScriptExport(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/share-access-example.csv');
        $parsed = R::parseCsv($raw);
        $this->assertSame([], $parsed['errors']);
        $this->assertCount(9, $parsed['rows']);
        $first = $parsed['rows'][0];
        $this->assertSame('fs01.exemple.test', $first['server']);
        $this->assertSame('Compta', $first['share']);
        $this->assertSame(R::SCOPE_SHARE, $first['scope']);
        $this->assertSame(R::LEVEL_MODIFY, $first['level']);
        $paie = $parsed['rows'][4];
        $this->assertSame(1, $paie['depth']);
        $this->assertTrue($paie['is_protected']);
        $this->assertSame(R::SCOPE_NTFS, $paie['scope']);
        $this->assertSame(R::LEVEL_FULL, $paie['level']);
    }

    public function testParseThirdPartyExportWithUncOnly(): void
    {
        $raw = "Path;IdentityReference;FileSystemRights;AccessControlType\n"
            . "\\\\fs02\\RH\\Dossiers;EXEMPLE\\GG_RH;Modify;Deny\n"
            . ";EXEMPLE\\GG_RH;Read;Allow\n";
        $parsed = R::parseCsv($raw);
        $this->assertSame([], $parsed['errors']);
        // La ligne sans chemin ni partage est ignorée.
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('fs02', $parsed['rows'][0]['server']);
        $this->assertSame('RH', $parsed['rows'][0]['share']);
        $this->assertTrue($parsed['rows'][0]['is_deny']);
    }

    public function testParseErrors(): void
    {
        $this->assertSame(['missing_identity'], R::parseCsv("Share,Rights\nCompta,Read\n")['errors']);
        $this->assertSame(['missing_rights'], R::parseCsv("Share,Identity\nCompta,Everyone\n")['errors']);
        $this->assertSame(['missing_share'], R::parseCsv("Identity,Rights\nEveryone,Read\n")['errors']);
        // Fichier piégé : du PHP ou un binaire n'est pas un export lisible.
        $this->assertNotSame([], R::parseCsv("<?php echo 'x'; ?>")['errors']);
        $this->assertNotSame([], R::parseCsv("%PDF-1.4\n1 0 obj\n")['errors']);
    }

    public function testFingerprintIsStableAndCaseInsensitive(): void
    {
        $row = [
            'server' => 'FS01', 'share' => 'Compta', 'folder' => '\\\\fs01\\Compta\\', 'scope' => 'ntfs',
            'identity' => 'EXEMPLE\\GG_Compta', 'sid' => '', 'level' => 'modify', 'is_deny' => false,
        ];
        $same = ['server' => 'fs01', 'folder' => '\\\\FS01\\compta', 'identity' => 'exemple\\gg_compta'] + $row;
        $this->assertSame(R::fingerprint($row), R::fingerprint($same));
        $this->assertNotSame(R::fingerprint($row), R::fingerprint(['level' => 'full'] + $row));
        $this->assertNotSame(R::fingerprint($row), R::fingerprint(['is_deny' => true] + $row));
    }

    public function testAceGaps(): void
    {
        $this->assertSame([R::GAP_BROAD_WRITE], R::aceGaps(['kind' => R::KIND_BROAD, 'level' => R::LEVEL_MODIFY]));
        $this->assertSame([], R::aceGaps(['kind' => R::KIND_BROAD, 'level' => R::LEVEL_READ]));
        $this->assertSame([], R::aceGaps(['kind' => R::KIND_BROAD, 'level' => R::LEVEL_FULL, 'is_deny' => true]));
        $this->assertSame([R::GAP_DELETED_ACCOUNT], R::aceGaps(['kind' => R::KIND_ORPHAN, 'level' => R::LEVEL_READ]));
        $this->assertSame(
            [R::GAP_DISABLED_ACCOUNT, R::GAP_DIRECT_USER],
            R::aceGaps(
                ['kind' => R::KIND_USER, 'level' => R::LEVEL_READ],
                ['is_active' => false, 'is_deleted' => false]
            )
        );
        $this->assertSame(
            [R::GAP_DELETED_ACCOUNT, R::GAP_DIRECT_USER],
            R::aceGaps(['kind' => R::KIND_USER, 'level' => R::LEVEL_READ], ['is_active' => true, 'is_deleted' => true])
        );
        $this->assertSame([], R::aceGaps(['kind' => R::KIND_GROUP, 'level' => R::LEVEL_FULL]));
        $this->assertSame([], R::aceGaps(['kind' => R::KIND_SYSTEM, 'level' => R::LEVEL_FULL]));
    }

    public function testShareGapsAndWeightedScore(): void
    {
        $folders = [['depth' => 0, 'is_protected' => false], ['depth' => 1, 'is_protected' => true]];
        $aceGaps = [[R::GAP_BROAD_WRITE], [], [R::GAP_DIRECT_USER]];
        $public = ['confidentiality' => 'low', 'integrity' => '', 'availability' => ''];
        $secret = ['confidentiality' => 'high', 'integrity' => '', 'availability' => ''];

        // Héritage cassé signalé seulement sur un partage sensible.
        $gaps = R::shareGaps($aceGaps, $folders, $public, true);
        $this->assertSame([R::GAP_BROAD_WRITE => 1, R::GAP_DIRECT_USER => 1], $gaps);
        $this->assertSame(4, R::score($gaps, $public));
        $this->assertSame('medium', R::scoreLevel(R::score($gaps, $public)));

        $gaps = R::shareGaps($aceGaps, $folders, $secret, false);
        $this->assertSame(1, $gaps[R::GAP_BROKEN_INHERITANCE]);
        $this->assertSame(1, $gaps[R::GAP_NO_OWNER]);
        // (3 + 1 + 1 + 1) x 3 pour une confidentialité élevée.
        $this->assertSame(18, R::score($gaps, $secret));
        $this->assertSame('high', R::scoreLevel(18));
        $this->assertSame('none', R::scoreLevel(0));
        $this->assertSame([], R::shareGaps([], $folders, $public, true));
    }
}
