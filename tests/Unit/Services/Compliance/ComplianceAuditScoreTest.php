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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Compliance;

use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore as Score;
use PHPUnit\Framework\TestCase;

final class ComplianceAuditScoreTest extends TestCase
{
    public function testEncryptableVolumes(): void
    {
        // Issue #308 : volumes d'un poste Windows chiffré avec BitLocker.
        $this->assertTrue(Score::isEncryptableVolume('C:', 'NTFS', 512000));
        $this->assertTrue(Score::isEncryptableVolume('D:\\', 'NTFS', 900000));
        $this->assertFalse(Score::isEncryptableVolume('Récupération', 'NTFS', 750), 'Partition sans lettre.');
        $this->assertFalse(Score::isEncryptableVolume('\\\\?\\Volume{1234}\\', 'NTFS', 100));
        $this->assertFalse(Score::isEncryptableVolume('', 'FAT32', 100), 'Partition EFI.');
        $this->assertFalse(Score::isEncryptableVolume('E:', 'FAT32', 16000), 'Clé USB.');
        $this->assertFalse(Score::isEncryptableVolume('H:', 'CIFS', 1000000), 'Lecteur réseau.');
        $this->assertFalse(Score::isEncryptableVolume('F:', '', 0), 'Lecteur optique vide.');
        $this->assertTrue(Score::isEncryptableVolume('C:', '', 0, false), 'Disque saisi à la main, sans détail.');
        // Linux et macOS.
        $this->assertTrue(Score::isEncryptableVolume('/', 'ext4', 100000));
        $this->assertTrue(Score::isEncryptableVolume('/home', 'xfs', 100000));
        $this->assertTrue(Score::isEncryptableVolume('/System/Volumes/Data', 'apfs', 100000));
        $this->assertFalse(Score::isEncryptableVolume('/boot', 'ext4', 1000));
        $this->assertFalse(Score::isEncryptableVolume('/boot/efi', 'vfat', 500));
        $this->assertFalse(Score::isEncryptableVolume('/run/user/1000', 'tmpfs', 800));
        $this->assertFalse(Score::isEncryptableVolume('/snap/core/1', 'squashfs', 100));
        $this->assertFalse(Score::isEncryptableVolume('/System/Volumes/Preboot', 'apfs', 100000));
    }

    public function testRate(): void
    {
        $this->assertSame(85, Score::rate(15, 100));
        $this->assertSame(0, Score::rate(12, 10));
        $this->assertSame(100, Score::rate(0, 3));
        $this->assertNull(Score::rate(0, 0));
    }

    public function testFrameworkScoresAverageEvaluatedChecksOnly(): void
    {
        $scores = Score::frameworkScores([
            'antivirus'         => ['gaps' => 1, 'total' => 4, 'evaluated' => true],   // 75
            'encryption'        => ['gaps' => 0, 'total' => 0, 'evaluated' => true],   // sans objet
            'inventory'         => ['gaps' => 5, 'total' => 10, 'evaluated' => true],  // 50
            'change_validation' => ['gaps' => 0, 'total' => 2, 'evaluated' => true],   // 100
            'accounts'          => ['gaps' => 9, 'total' => 10, 'evaluated' => false], // droit manquant
        ]);
        $this->assertSame(['score' => 75, 'checks' => 3], $scores[Score::ISO27001]);
        $this->assertSame(['score' => 75, 'checks' => 3], $scores[Score::NIS2]);
        $this->assertSame(['score' => 75, 'checks' => 1], $scores[Score::GDPR]);
        $this->assertSame(['score' => null, 'checks' => 0], Score::frameworkScores([])[Score::GDPR]);
    }

    public function testAntivirusCompliance(): void
    {
        $ok = ['is_active' => 1, 'is_uptodate' => 1, 'date_expiration' => null];
        $this->assertTrue(Score::isAntivirusCompliant($ok, '2099-01-01'));
        $this->assertFalse(Score::isAntivirusCompliant(['is_uptodate' => 0] + $ok, '2099-01-01'));
        $this->assertFalse(Score::isAntivirusCompliant(['is_active' => 0] + $ok, '2099-01-01'));
        $this->assertFalse(Score::isAntivirusCompliant(['date_expiration' => '2098-12-31'] + $ok, '2099-01-01'));
        $this->assertTrue(Score::isAntivirusCompliant(['date_expiration' => '2099-01-01'] + $ok, '2099-01-01'));
    }
}
