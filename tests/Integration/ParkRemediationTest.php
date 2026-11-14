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

use GlpiPlugin\Grcmanager\NotificationTargetVulnerabilityDigest;
use GlpiPlugin\Grcmanager\ParkRemediation;
use GlpiPlugin\Grcmanager\ParkVulnerability;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use GlpiPlugin\Grcmanager\Services\Cve\RemediationRules as Rules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;
use GlpiPlugin\Grcmanager\Services\Notification\MoCatalog;
use GlpiPlugin\Grcmanager\Services\Notification\NotificationTemplateDefaults;
use GlpiPlugin\Grcmanager\Services\Waiver\WaiverRules;
use GlpiPlugin\Grcmanager\VulnerabilityDigest;
use GlpiPlugin\Grcmanager\Waiver;
use NotificationTarget;

/**
 * Issue #601 : délais de correction des vulnérabilités du parc, sur un parc fictif et sans appel
 * réseau (réponses de NVD, KEV et EPSS posées directement, comme les écrirait la synchronisation).
 * Les machines sont dans une entité de test : les indicateurs lus avec cette seule entité active ne
 * comptent que ce parc.
 */
final class ParkRemediationTest extends GrcmanagerIntegrationTestCase
{
    private const KEV = 'CVE-2099-60101';
    private const CRITICAL = 'CVE-2099-60102';
    private const HIGH = 'CVE-2099-60103';
    private const LOW = 'CVE-2099-60104';
    private const ALL = [self::KEV, self::CRITICAL, self::HIGH, self::LOW];

    /** @var array<string, mixed> */
    private array $savedSettings = [];

    private int $entityId = 0;

    private int $productId = 0;

    /** @var list<int> */
    private array $computers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSettings = ParkRemediation::getSettings();
        ParkRemediation::saveSettings(['park_sla_enabled' => 1]);
    }

    protected function tearDown(): void
    {
        $restore = [
            'park_sla_enabled'          => (int) $this->savedSettings['enabled'],
            'park_sla_critical_percent' => $this->savedSettings['critical_percent'],
        ];
        foreach ($this->savedSettings['days'] as $level => $days) {
            $restore['park_sla_days_' . $level] = $days;
        }
        ParkRemediation::saveSettings($restore);
        parent::tearDown();
    }

    /**
     * Un logiciel fictif, version 4.0 sur deux machines (dont une classée élevée en confidentialité),
     * avec quatre CVE de niveaux différents.
     */
    private function fleet(): void
    {
        global $DB;

        $this->entityId = $this->createTestEntity(0, 'PhpUnit remediation ' . random_int(1000, 9999));
        $name = 'PhpUnitRemediation Viewer ' . random_int(1000, 9999);
        $softwareId = (int) (new \Software())->add(['name' => $name, 'entities_id' => $this->entityId]);
        $versionId = (int) (new \SoftwareVersion())->add([
            'name' => '4.0', 'softwares_id' => $softwareId, 'entities_id' => $this->entityId,
        ]);
        for ($i = 0; $i < 2; $i++) {
            $computerId = (int) (new \Computer())->add([
                'name' => 'PC remediation ' . random_int(1, 999999), 'entities_id' => $this->entityId,
            ]);
            (new \Item_SoftwareVersion())->add([
                'items_id' => $computerId, 'itemtype' => \Computer::class,
                'softwareversions_id' => $versionId, 'entities_id' => $this->entityId,
            ]);
            $this->computers[] = $computerId;
        }
        $this->productId = (int) ParkVulnerability::confirm(
            $name,
            'cpe:2.3:a:phpunitremediation:viewer:4.0:*:*:*:*:*:*:*'
        );
        ParkVulnerabilitySync::replaceParkCves($this->productId, '4.0', self::ALL);
        foreach ([self::KEV => 6.5, self::CRITICAL => 9.8, self::HIGH => 7.5, self::LOW => 3.1] as $cve => $cvss) {
            $DB->delete('glpi_plugin_grcmanager_cveenrichments', ['cve_id' => $cve]);
            $DB->insert('glpi_plugin_grcmanager_cveenrichments', [
                'cve_id' => $cve, 'cvss_score' => $cvss, 'severity' => 'HIGH', 'fetch_status' => 'ok',
            ]);
        }
        ParkVulnerabilitySync::storeKev([
            self::KEV => ['date_added' => '2026-10-01', 'due_date' => '2026-10-22', 'ransomware' => false],
        ]);
    }

    private function classifyFirstComputerHigh(): void
    {
        global $DB;

        $DB->insert('glpi_plugin_grcmanager_assetclassifications', [
            'itemtype' => \Computer::class, 'items_id' => $this->computers[0], 'confidentiality' => 'high',
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function ourRecords(): array
    {
        $records = [];
        foreach (ParkRemediation::records([self::KEV, self::CRITICAL, self::HIGH, self::LOW]) as $record) {
            if ($record['canonical_id'] === $this->productId) {
                $records[$record['cve_id']] = $record;
            }
        }

        return $records;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function ourRows(string $today): array
    {
        $rows = [];
        foreach (ParkRemediation::annotate(ParkVulnerability::getVulnerabilityRows(false, []), $today) as $row) {
            if ($row['canonical_id'] === $this->productId) {
                $rows[$row['cve_id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function ourIndicators(string $today): array
    {
        $active = $_SESSION['glpiactiveentities'];
        $_SESSION['glpiactiveentities'] = [$this->entityId];
        try {
            $indicators = ParkRemediation::indicators(true, $today);
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
        }
        $this->assertNotNull($indicators);

        return $indicators;
    }

    public function testSettingsAreDisabledByDefaultAndSavedBounded(): void
    {
        ParkRemediation::saveSettings([]);
        $this->assertFalse(ParkRemediation::isEnabled());
        $this->assertSame(Rules::DEFAULT_DAYS, ParkRemediation::getSettings()['days']);
        $this->assertNull(ParkRemediation::indicators(), 'Politique désactivée : pas d\'indicateur.');
        $this->assertSame('', ParkRemediation::digestSummary());

        ParkRemediation::saveSettings([
            'park_sla_enabled' => '1', 'park_sla_days_kev' => '3', 'park_sla_days_high' => '-5',
            'park_sla_critical_percent' => '500',
        ]);
        $settings = ParkRemediation::getSettings();
        $this->assertTrue($settings['enabled']);
        $this->assertSame(3, $settings['days'][Rules::KEV]);
        $this->assertSame(30, $settings['days'][Rules::HIGH]);
        $this->assertSame(100, $settings['critical_percent']);
    }

    public function testFirstDetectionIsKeptAfterANewNvdCheck(): void
    {
        $this->fleet();
        ParkRemediation::sync('2026-10-01');
        $records = $this->ourRecords();
        $this->assertCount(4, $records);
        $this->assertSame('2026-10-01', $records[self::HIGH]['date_first_seen']);
        $this->assertSame(Rules::KEV, $records[self::KEV]['level']);
        $this->assertSame(Rules::LOW, $records[self::LOW]['level']);
        $this->assertSame([$this->entityId], $records[self::HIGH]['entities']);

        // Nouvelle vérification auprès de NVD : la table des CVE du parc est vidée puis remplie.
        ParkVulnerabilitySync::replaceParkCves($this->productId, '4.0', self::ALL);
        ParkRemediation::sync('2026-10-20');
        $records = $this->ourRecords();
        $this->assertSame('2026-10-01', $records[self::HIGH]['date_first_seen']);
        $this->assertSame('2026-10-20', $records[self::HIGH]['date_last_seen']);
        $this->assertNull($records[self::HIGH]['date_fixed']);
    }

    public function testDueDatesOverdueAndFixedVulnerabilities(): void
    {
        $this->fleet();
        ParkRemediation::sync('2026-10-01');

        $rows = $this->ourRows('2026-10-20');
        $this->assertSame('2026-10-08', $rows[self::KEV]['remediation']['due']);
        $this->assertSame(Rules::STATUS_OVERDUE, $rows[self::KEV]['remediation']['status']);
        $this->assertSame(Rules::STATUS_OVERDUE, $rows[self::CRITICAL]['remediation']['status'], 'Échéance le 16.');
        $this->assertSame(Rules::STATUS_ON_TIME, $rows[self::HIGH]['remediation']['status']);
        $this->assertSame('2026-10-31', $rows[self::HIGH]['remediation']['due']);
        $this->assertSame('2026-10-01', $rows[self::HIGH]['remediation']['first_seen']);
        $this->assertNotSame('', $rows[self::KEV]['remediation']['badge']['label']);
        $this->assertSame('bg-red text-white', $rows[self::KEV]['remediation']['badge']['class']);

        // La version corrigée ne porte plus la CVE élevée : corrigée le 25, dans les délais.
        ParkVulnerabilitySync::replaceParkCves($this->productId, '4.0', [self::KEV, self::CRITICAL, self::LOW]);
        ParkRemediation::sync('2026-10-25');
        $this->assertSame('2026-10-25', $this->ourRecords()[self::HIGH]['date_fixed']);
        $this->assertArrayNotHasKey(self::HIGH, $this->ourRows('2026-10-25'));

        $indicators = $this->ourIndicators('2026-10-25');
        $this->assertSame(3, $indicators['open']);
        $this->assertSame(2, $indicators['overdue']);
        $this->assertSame(1, $indicators['fixed']);
        $this->assertSame(100, $indicators['rate']);
        $this->assertSame(24.0, $indicators['mttr']);
        $this->assertSame(17, $indicators['oldest_overdue'], 'KEV : échéance le 8, on est le 25.');
        $this->assertSame(1, $indicators['by_level'][Rules::KEV]['overdue']);
        $this->assertSame(1, $indicators['by_level'][Rules::CRITICAL]['overdue']);
        $this->assertSame(50, $indicators['compliance'], '(3 ouvertes - 2 en retard + 1 corrigée) / 4.');

        // Un autre périmètre ne voit rien de ce parc.
        $active = $_SESSION['glpiactiveentities'];
        $_SESSION['glpiactiveentities'] = [$this->entityId + 1];
        try {
            $this->assertSame(0, ParkRemediation::indicators(true, '2026-10-25')['open'] ?? null);
        } finally {
            $_SESSION['glpiactiveentities'] = $active;
        }
    }

    public function testCriticalAssetsGetAShorterDeadline(): void
    {
        $this->fleet();
        $this->classifyFirstComputerHigh();
        ParkRemediation::sync('2026-10-01');
        $rows = $this->ourRows('2026-10-02');
        $this->assertTrue($rows[self::HIGH]['remediation']['critical']);
        $this->assertSame('2026-10-16', $rows[self::HIGH]['remediation']['due'], '30 jours réduits de moitié.');
    }

    public function testWaiverInProgressThenExpired(): void
    {
        global $DB;

        $this->fleet();
        ParkRemediation::sync('2026-08-01');

        $waiver = new Waiver();
        $waiverId = (int) $waiver->add([
            'name'     => 'Dérogation fictive serveur métier',
            'date_end' => '2026-12-31',
            'cve_ids'  => self::CRITICAL . ', ' . strtolower(self::HIGH) . ' et du texte <b>libre</b>',
        ]);
        $this->assertGreaterThan(0, $waiverId);
        $this->assertSame([self::CRITICAL, self::HIGH], ParkRemediation::getWaiverCves($waiverId));

        // Demandée seulement : aucun effet.
        $this->assertSame(Rules::STATUS_OVERDUE, $this->ourRows('2026-11-14')[self::CRITICAL]['remediation']['status']);

        // Validée (par une autre personne) : la CVE suit la date de fin.
        $DB->update(Waiver::getTable(), ['status' => WaiverRules::STATUS_APPROVED], ['id' => $waiverId]);
        $row = $this->ourRows('2026-11-14')[self::CRITICAL];
        $this->assertSame(Rules::STATUS_WAIVED, $row['remediation']['status']);
        $this->assertSame('2026-12-31', $row['remediation']['due']);
        $this->assertSame(Waiver::getFormURLWithID($waiverId), $row['remediation']['waiver']['url']);
        $this->assertSame(1, $this->ourIndicators('2026-11-14')['by_level'][Rules::HIGH]['open']);
        $this->assertSame(2, $this->ourIndicators('2026-11-14')['waived']);

        // Échue : de nouveau en retard.
        $this->assertSame(Rules::STATUS_OVERDUE, $this->ourRows('2027-01-02')[self::CRITICAL]['remediation']['status']);

        // Le formulaire remplace la liste ; une mise à jour sans le champ n'y touche pas.
        $waiver->update(['id' => $waiverId, 'cve_ids' => self::HIGH]);
        $this->assertSame([self::HIGH], ParkRemediation::getWaiverCves($waiverId));
        $waiver->update(['id' => $waiverId, 'name' => 'Dérogation fictive renommée']);
        $this->assertSame([self::HIGH], ParkRemediation::getWaiverCves($waiverId));
        $waiver->delete(['id' => $waiverId], true);
        $this->assertSame([], ParkRemediation::getWaiverCves($waiverId));
    }

    public function testDigestContainsTheRemediationSection(): void
    {
        $this->fleet();
        ParkRemediation::sync(date('Y-m-d', strtotime('-40 days')));
        $report = VulnerabilityDigest::build();
        $this->assertNotSame('', $report['remediation']);
        $this->assertStringContainsString('2', $report['remediation'], 'Au moins nos deux vulnérabilités en retard.');

        $digest = new VulnerabilityDigest();
        $digest->getFromDB((int) $digest->add([
            'entities_id' => 0, 'urgency' => $report['urgency'], 'cve_keys' => '[]',
        ]));
        $target = NotificationTarget::getInstance($digest, VulnerabilityDigest::EVENT, ['report' => $report]);
        $this->assertInstanceOf(NotificationTargetVulnerabilityDigest::class, $target);
        $target->addDataForTemplate(VulnerabilityDigest::EVENT, ['report' => $report]);
        $this->assertSame($report['remediation'], $target->data['##vulndigest.remediation##']);
        $signals = array_column($target->data['vulnerabilities'], '##vulnerability.signal##', '##vulnerability.cve##');
        $this->assertArrayHasKey(self::KEV, $signals);
        $due = (string) \Html::convDate(date('Y-m-d', strtotime('-33 days')));
        $this->assertStringContainsString($due, $signals[self::KEV], 'Échéance des exploitées (7 jours).');

        ParkRemediation::saveSettings([]);
        $this->assertSame('', VulnerabilityDigest::build()['remediation'], 'Politique désactivée : section vide.');
    }

    public function testDigestTemplateGetsTheRemediationTagOnce(): void
    {
        $default = NotificationTemplateDefaults::build('vulnerability_digest', MoCatalog::translator(''));
        $this->assertStringContainsString('##vulndigest.remediation##', $default['content_text']);
        $this->assertStringContainsString('##IFvulndigest.remediation##', $default['content_html']);
        $this->assertNull(
            NotificationTemplateDefaults::withRemediation($default['content_text'], $default['content_html']),
            'Déjà présente : rien à changer.'
        );

        // Modèle d'une version précédente : la balise est ajoutée après celle des rançongiciels.
        $old = NotificationTemplateDefaults::withRemediation(
            'Avant ##vulndigest.ransomware## après',
            '<p>##IFvulndigest.ransomware##x##ENDIFvulndigest.ransomware##</p>'
        );
        $this->assertNotNull($old);
        $this->assertSame("Avant ##vulndigest.ransomware##\n##vulndigest.remediation## après", $old['content_text']);
        $this->assertStringContainsString(
            '##ENDIFvulndigest.ransomware####IFvulndigest.remediation##',
            $old['content_html']
        );
        $this->assertNull(NotificationTemplateDefaults::withRemediation('Texte réécrit', '<p>Texte réécrit</p>'));
    }

    public function testDashboardCardsFollowTheFleetVulnerabilityRights(): void
    {
        $this->fleet();
        ParkRemediation::sync(date('Y-m-d', strtotime('-40 days')));
        $this->assertTrue(SecuredDashboardCards::canShow('vulnerabilityRemediationOverdue'));
        $this->assertGreaterThanOrEqual(2, SecuredDashboardCards::vulnerabilityRemediationOverdue()['number']);
        $this->assertCount(5, SecuredDashboardCards::vulnerabilityRemediationOverdueByLevel()['data']);
        $trend = SecuredDashboardCards::vulnerabilityRemediationTrend()['data'];
        $this->assertCount(Rules::TREND_MONTHS, $trend['labels']);

        $saved = $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] ?? 0;
        $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = 0;
        try {
            $this->assertFalse(SecuredDashboardCards::canShow('vulnerabilityRemediationOverdue'));
            $this->assertSame(0, SecuredDashboardCards::vulnerabilityRemediationOverdue()['number']);
            $this->assertSame([], SecuredDashboardCards::vulnerabilityRemediationOverdueByLevel()['data']);
        } finally {
            $_SESSION['glpiactiveprofile']['plugin_grcmanager_sensitive'] = $saved;
        }
    }

    public function testTicketShowsTheDeadlineWithoutChangingIt(): void
    {
        $this->fleet();
        ParkRemediation::sync(date('Y-m-d', strtotime('-40 days')));
        $rows = array_values(array_filter(
            ParkVulnerability::getVulnerabilityRows(false, []),
            fn (array $row): bool => $row['canonical_id'] === $this->productId
        ));
        $ticketsId = ParkVulnerability::createTicket($rows);
        $ticket = new \Ticket();
        $this->assertTrue($ticket->getFromDB($ticketsId));
        $due = (string) \Html::convDate(date('Y-m-d', strtotime('-33 days')));
        $this->assertStringContainsString($due, (string) $ticket->fields['content'], 'La plus proche : KEV, 7 jours.');
        $this->assertSame(5, (int) $ticket->fields['urgency'], 'Urgence calculée comme avant, sans le retard.');
    }
}
