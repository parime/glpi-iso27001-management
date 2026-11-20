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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\DiskEncryption;
use GlpiPlugin\Grcmanager\Install\DiskEncryptionTables;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Compliance\DiskEncryptionRules as Rules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #847 : chiffrement des disques du parc, sur des ordinateurs et des volumes fictifs d'une
 * entité de test (seule entité active) et d'une entité cachée.
 */
final class DiskEncryptionTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private int $laptopType;

    private int $desktopType;

    /** @var array<string, int> */
    private array $pc = [];

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        DiskEncryption::reset();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $suffix = (string) random_int(1000, 9999);
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Parc ' . $suffix);
        $hidden = $this->createTestEntity(0, 'PhpUnitDemo Filiale ' . $suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;

        $this->laptopType = (int) (new \ComputerType())->import(['name' => 'Notebook']);
        $this->desktopType = (int) (new \ComputerType())->import(['name' => 'PhpUnitDemo Desktop ' . $suffix]);
        $computer = fn (string $name, int $type, int $entity = 0): int => (int) (new \Computer())->add([
            'name'             => 'PhpUnitDemo ' . $name,
            'entities_id'      => $entity > 0 ? $entity : $this->entity,
            'computertypes_id' => $type,
        ]);
        $this->pc = [
            'laptop_clear'    => $computer('portable en clair', $this->laptopType),
            'laptop_ok'       => $computer('portable chiffré', $this->laptopType),
            'desktop_weak'    => $computer('poste DES', $this->desktopType),
            'desktop_unknown' => $computer('poste sans agent', $this->desktopType),
            'desktop_partial' => $computer('poste partiel', $this->desktopType),
        ];
        $this->pc['hidden'] = (int) (new \Computer())->add([
            'name' => 'PhpUnitDemo portable caché', 'entities_id' => $hidden, 'computertypes_id' => $this->laptopType,
        ]);
        foreach ($this->pc as $id) {
            $this->assertGreaterThan(0, $id);
        }
        $this->disk('laptop_clear', 'C:', Rules::DISK_NO);
        $this->disk('laptop_ok', 'C:', Rules::DISK_YES, 'BitLocker', 'XTS-AES-128');
        $this->disk('desktop_weak', 'C:', Rules::DISK_YES, 'VeraCrypt', 'DES');
        $this->disk('desktop_partial', 'C:', Rules::DISK_YES, 'BitLocker', 'AES_128_WITH_DIFFUSER');
        $this->disk('desktop_partial', 'D:', Rules::DISK_NO);
        $this->disk('hidden', 'C:', Rules::DISK_NO);
        global $DB;
        $DB->insert('glpi_plugin_grcmanager_assetclassifications', [
            'itemtype' => \Computer::class, 'items_id' => $this->pc['desktop_partial'], 'confidentiality' => 'high',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        DiskEncryption::reset();
        parent::tearDown();
    }

    private function disk(string $pc, string $mountpoint, int $status, string $tool = '', string $algorithm = ''): void
    {
        global $DB;

        $DB->insert('glpi_items_disks', [
            'itemtype'             => \Computer::class,
            'items_id'             => $this->pc[$pc],
            'name'                 => 'Disque ' . $mountpoint,
            'mountpoint'           => $mountpoint,
            'is_deleted'           => 0,
            'encryption_status'    => $status,
            'encryption_tool'      => $tool,
            'encryption_algorithm' => $algorithm,
        ]);
    }

    /**
     * @return list<int>
     */
    private static function gapIds(array $result): array
    {
        return array_column($result['gaps'], 'id');
    }

    public function testDefaultRuleCoversAllComputersAndFlagsWeakAlgorithms(): void
    {
        $result = ComplianceAudit::runCheck('encryption');
        $gaps = self::gapIds($result);
        sort($gaps);
        $expected = [$this->pc['laptop_clear'], $this->pc['desktop_weak'], $this->pc['desktop_partial']];
        sort($expected);
        $this->assertSame($expected, $gaps);
        $this->assertSame(4, $result['total'], 'Le poste sans volume inventorié n\'est pas compté.');
        $weak = array_values(array_filter(
            $result['gaps'],
            fn (array $gap): bool => $gap['id'] === $this->pc['desktop_weak']
        ));
        $this->assertStringContainsString('DES', $weak[0]['detail']);

        $laptops = ComplianceAudit::runCheck('laptop_encryption');
        $this->assertSame([$this->pc['laptop_clear']], self::gapIds($laptops));
        $this->assertSame(2, $laptops['total']);
        $this->assertSame(50.0, ComplianceAudit::gapRate('laptop_encryption', [$this->entity]));
        $this->assertEquals(ComplianceAudit::run()['laptop_encryption'], $laptops, 'Même résultat que la page.');
    }

    public function testConfigurableRule(): void
    {
        $rule = ComplianceAudit::setEncryptionRule(['scope' => 'laptops', 'types' => [999999999]]);
        $this->assertSame([], $rule['types'], 'Type inexistant écarté.');
        $this->assertSame([$this->pc['laptop_clear']], self::gapIds(ComplianceAudit::runCheck('encryption')));

        ComplianceAudit::setEncryptionRule(['scope' => 'laptops', 'confidentiality' => 'high']);
        $gaps = self::gapIds(ComplianceAudit::runCheck('encryption'));
        $this->assertContains($this->pc['desktop_partial'], $gaps, 'Classé en confidentialité élevée.');
        $this->assertNotContains($this->pc['desktop_weak'], $gaps);

        ComplianceAudit::setEncryptionRule(['scope' => 'types', 'types' => [(string) $this->desktopType]]);
        $this->assertSame(
            ['scope' => 'types', 'types' => [$this->desktopType], 'confidentiality' => ''],
            ComplianceAudit::getEncryptionRule()
        );
        $gaps = self::gapIds(ComplianceAudit::runCheck('encryption'));
        $this->assertNotContains($this->pc['laptop_clear'], $gaps);
        $this->assertContains($this->pc['desktop_weak'], $gaps);
        $this->assertSame(
            [$this->pc['laptop_clear']],
            self::gapIds(ComplianceAudit::runCheck('laptop_encryption')),
            'Le contrôle des portables ne dépend pas de la règle.'
        );
    }

    public function testPageDataStaysInTheSessionEntities(): void
    {
        $data = DiskEncryption::pageData();
        $ids = array_column($data['rows'], 'id');
        $this->assertNotContains($this->pc['hidden'], $ids, 'Entité hors de la session.');
        $this->assertContains($this->pc['desktop_unknown'], $ids);
        $this->assertSame($this->pc['laptop_clear'], $data['rows'][0]['id'], 'Écarts d\'abord, par nom.');
        $this->assertSame(5, $data['summary']['required']['total']);
        $this->assertSame(1, $data['summary']['required'][Rules::STATUS_UNKNOWN]);
        $this->assertSame(1, $data['summary']['weak']);
        $this->assertSame(1, $data['summary']['legacy']);
        $this->assertSame(50, $data['summary']['laptops']['rate']);
        $this->assertSame(['Notebook'], array_values(array_filter(
            array_column($data['by_type'], 'label'),
            static fn (string $label): bool => $label === 'Notebook'
        )));
        $ok = array_values(array_filter($data['rows'], fn (array $r): bool => $r['id'] === $this->pc['laptop_ok']))[0];
        $this->assertSame(['BitLocker'], $ok['tools']);
        $this->assertSame(['XTS-AES-128'], $ok['algorithms']);
        $this->assertSame(Rules::STATUS_ENCRYPTED, $ok['status']);
    }

    public function testRecoveryKeyIsOnlyDeclared(): void
    {
        $this->assertTrue(DiskEncryption::declareKey($this->pc['laptop_ok'], true, '2026-01-15'));
        $this->assertSame(
            [$this->pc['laptop_ok'] => ['is_backed_up' => true, 'backup_date' => '2026-01-15']],
            DiskEncryption::keyDeclarations([$this->pc['laptop_ok']])
        );
        $this->assertTrue(DiskEncryption::declareKey($this->pc['laptop_ok'], true, '2999-01-01'));
        $declarations = DiskEncryption::keyDeclarations([$this->pc['laptop_ok']]);
        $this->assertSame('', $declarations[$this->pc['laptop_ok']]['backup_date'], 'Date future écartée.');
        $this->assertSame(
            1,
            countElementsInTable(DiskEncryptionTables::KEYS, ['computers_id' => $this->pc['laptop_ok']])
        );
        $this->assertFalse(
            DiskEncryption::declareKey($this->pc['hidden'], true, '2026-01-15'),
            'Ordinateur hors entités.'
        );
        $this->assertFalse(DiskEncryption::declareKey(999999999, true, '2026-01-15'));

        $right = $_SESSION['glpiactiveprofile']['plugin_grcmanager'];
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = READ;
        try {
            $this->assertFalse(DiskEncryption::declareKey($this->pc['laptop_clear'], true, ''), 'Lecture seule.');
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = $right;
        }
        global $DB;
        $columns = array_keys($DB->listFields(DiskEncryptionTables::KEYS));
        sort($columns);
        $this->assertSame(
            ['backup_date', 'computers_id', 'date_mod', 'id', 'is_backed_up', 'users_id'],
            $columns,
            'Aucune colonne pour une clé.'
        );
    }

    public function testEvidenceDashboardKriAndRights(): void
    {
        $evidence = DiskEncryption::evidence('A.8.24');
        $this->assertNotNull($evidence);
        $this->assertSame(3, $evidence['gaps']);
        $this->assertSame(50, $evidence['laptop_rate']);
        $this->assertNotNull(DiskEncryption::evidence('A.8.1'));
        $this->assertNull(DiskEncryption::evidence('A.5.1'));
        $this->assertNotNull(DiskEncryption::readinessFact());
        $this->assertArrayHasKey('unencrypted_laptops', RiskIndicator::getSources());
        $this->assertTrue(SecuredDashboardCards::canShow('diskEncryptionRate'));
        $this->assertSame($evidence['rate'], SecuredDashboardCards::diskEncryptionRate()['number']);

        $right = $_SESSION['glpiactiveprofile']['computer'];
        $_SESSION['glpiactiveprofile']['computer'] = 0;
        DiskEncryption::reset();
        try {
            $this->assertFalse(DiskEncryption::canView());
            $this->assertNull(DiskEncryption::evidence('A.8.24'));
            $this->assertFalse(SecuredDashboardCards::canShow('diskEncryptionRate'));
            $this->assertSame(0, SecuredDashboardCards::diskEncryptionRate()['number']);
            $this->assertFalse(ComplianceAudit::runCheck('laptop_encryption')['evaluated']);
        } finally {
            $_SESSION['glpiactiveprofile']['computer'] = $right;
        }
    }
}
