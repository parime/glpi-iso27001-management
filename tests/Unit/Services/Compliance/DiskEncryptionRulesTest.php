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

use GlpiPlugin\Grcmanager\Services\Compliance\DiskEncryptionRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #847 : chiffrement des disques du parc, logique pure sur des volumes fictifs.
 */
final class DiskEncryptionRulesTest extends TestCase
{
    public function testComputerStatusFromVolumes(): void
    {
        $this->assertSame(R::STATUS_UNKNOWN, R::computerStatus([]), 'Aucun volume inventorié.');
        $this->assertSame(R::STATUS_ENCRYPTED, R::computerStatus([R::DISK_YES, R::DISK_YES]));
        $this->assertSame(R::STATUS_PARTIAL, R::computerStatus([R::DISK_YES, R::DISK_NO]));
        $this->assertSame(R::STATUS_PARTIAL, R::computerStatus([R::DISK_PARTIALLY]), 'Chiffrement en cours.');
        $this->assertSame(R::STATUS_CLEAR, R::computerStatus([R::DISK_NO, R::DISK_NO]));
    }

    public function testLaptopTypes(): void
    {
        foreach (['Laptop', 'notebook', ' Portable ', 'Sub Notebook', 'Convertible', 'Tablet', 'Detachable'] as $type) {
            $this->assertTrue(R::isLaptopType($type), $type);
        }
        foreach (['', 'Desktop', 'Server', 'Mini Tower', 'Laptop stand'] as $type) {
            $this->assertFalse(R::isLaptopType($type), $type);
        }
    }

    public function testAlgorithmStrength(): void
    {
        $this->assertSame(R::ALGO_UNKNOWN, R::algorithmStrength(''));
        $this->assertSame(R::ALGO_OK, R::algorithmStrength('XTS-AES-256'));
        $this->assertSame(R::ALGO_OK, R::algorithmStrength('aes-xts-plain64'), 'LUKS2 par défaut.');
        $this->assertSame(R::ALGO_OK, R::algorithmStrength('AES-XTS'), 'FileVault.');
        $this->assertSame(R::ALGO_LEGACY, R::algorithmStrength('AES_128_WITH_DIFFUSER'), 'BitLocker ancien.');
        $this->assertSame(R::ALGO_LEGACY, R::algorithmStrength('aes-cbc-essiv:sha256'), 'LUKS1.');
        $this->assertSame(R::ALGO_WEAK, R::algorithmStrength('DES'));
        $this->assertSame(R::ALGO_WEAK, R::algorithmStrength('3DES-CBC'));
        $this->assertSame(R::ALGO_WEAK, R::algorithmStrength('RC4'));
        $this->assertSame(R::ALGO_OK, R::algorithmStrength('Serpent-XTS'), 'Pas de faux positif.');
        $this->assertSame(R::ALGO_WEAK, R::weakestStrength(['XTS-AES-128', 'Blowfish']));
        $this->assertSame(R::ALGO_LEGACY, R::weakestStrength(['XTS-AES-128', 'AES-CBC']));
        $this->assertSame(R::ALGO_UNKNOWN, R::weakestStrength([]));
    }

    public function testRuleNormalization(): void
    {
        $this->assertSame(['scope' => R::SCOPE_ALL, 'types' => [], 'confidentiality' => ''], R::normalizeRule(null));
        $this->assertSame(
            ['scope' => R::SCOPE_TYPES, 'types' => [2, 5], 'confidentiality' => 'high'],
            R::normalizeRule(['scope' => 'types', 'types' => ['5', 2, 'x', -1, 5, [3]], 'confidentiality' => 'high'])
        );
        $this->assertSame(
            ['scope' => R::SCOPE_ALL, 'types' => [], 'confidentiality' => ''],
            R::normalizeRule(['scope' => 'everything', 'confidentiality' => 'low'])
        );
    }

    public function testRequiredComputers(): void
    {
        $all = R::normalizeRule([]);
        $this->assertTrue(R::isRequired($all, false, 0));
        $laptops = R::normalizeRule(['scope' => 'laptops']);
        $this->assertTrue(R::isRequired($laptops, true, 3));
        $this->assertFalse(R::isRequired($laptops, false, 3));
        $types = R::normalizeRule(['scope' => 'types', 'types' => [7]]);
        $this->assertTrue(R::isRequired($types, false, 7));
        $this->assertFalse(R::isRequired($types, true, 8), 'Un portable d\'un autre type n\'est pas concerné.');
        $this->assertFalse(R::isRequired($types, false, 0));
        // Seuil de confidentialité : s'ajoute à la portée.
        $classified = R::normalizeRule(['scope' => 'laptops', 'confidentiality' => 'medium']);
        $this->assertTrue(R::isRequired($classified, false, 1, 'high'));
        $this->assertTrue(R::isRequired($classified, false, 1, 'medium'));
        $this->assertFalse(R::isRequired($classified, false, 1, 'low'));
        $this->assertFalse(R::isRequired($classified, false, 1, ''));
        $high = R::normalizeRule(['scope' => 'laptops', 'confidentiality' => 'high']);
        $this->assertFalse(R::isRequired($high, false, 1, 'medium'));
    }

    public function testGaps(): void
    {
        $this->assertTrue(R::isGap(true, R::STATUS_CLEAR, R::ALGO_UNKNOWN));
        $this->assertTrue(R::isGap(true, R::STATUS_PARTIAL, R::ALGO_OK));
        $this->assertTrue(R::isGap(true, R::STATUS_ENCRYPTED, R::ALGO_WEAK), 'Chiffré avec un algorithme faible.');
        $this->assertFalse(R::isGap(true, R::STATUS_ENCRYPTED, R::ALGO_LEGACY), 'Ancien : signalé, pas un écart.');
        $this->assertFalse(R::isGap(true, R::STATUS_UNKNOWN, R::ALGO_UNKNOWN), 'Inconnu : compté à part.');
        $this->assertFalse(R::isGap(false, R::STATUS_CLEAR, R::ALGO_UNKNOWN), 'Ordinateur non concerné.');
    }

    public function testBreakdownAndSummary(): void
    {
        $rows = [
            ['status' => R::STATUS_ENCRYPTED, 'entity' => 'Siège'],
            ['status' => R::STATUS_ENCRYPTED, 'entity' => 'Siège'],
            ['status' => R::STATUS_CLEAR, 'entity' => 'Siège'],
            ['status' => R::STATUS_UNKNOWN, 'entity' => 'Agence'],
            ['status' => R::STATUS_PARTIAL, 'entity' => 'agence 2'],
        ];
        $groups = R::breakdown($rows, 'entity');
        $this->assertSame(['Agence', 'agence 2', 'Siège'], array_column($groups, 'label'));
        $this->assertNull($groups[0]['rate'], 'Aucun état connu.');
        $this->assertSame(0, $groups[1]['rate']);
        $this->assertSame(66, $groups[2]['rate'], 'Arrondi par défaut : jamais 100 % avec un écart.');
        $summary = R::summary($rows);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(1, $summary[R::STATUS_UNKNOWN]);
        $this->assertSame(50, $summary['rate']);
        $this->assertNull(R::summary([])['rate']);
    }

    public function testBackupDate(): void
    {
        $this->assertSame('2026-01-15', R::normalizeBackupDate('2026-01-15', '2026-02-01'));
        $this->assertNull(R::normalizeBackupDate('2026-03-01', '2026-02-01'), 'Date future.');
        $this->assertNull(R::normalizeBackupDate('2026-02-30', '2026-12-01'));
        $this->assertNull(R::normalizeBackupDate('<script>', '2026-12-01'));
        $this->assertNull(R::normalizeBackupDate(['2026-01-01'], '2026-12-01'));
    }

    public function testRuleWithoutConfidentialityKeyIsReadWithoutWarning(): void
    {
        // Règle enregistrée avant le seuil de confidentialité : la clé est absente, et la valeur
        // vide fait partie des seuils autorisés. La lecture ne doit déclencher aucun avertissement.
        $rule = R::normalizeRule(['scope' => R::SCOPE_ALL, 'types' => []]);
        $this->assertSame('', $rule['confidentiality']);
    }
}
