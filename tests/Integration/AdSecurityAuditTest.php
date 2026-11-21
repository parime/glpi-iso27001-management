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

use GlpiPlugin\Grcmanager\AdSecurityAudit;
use GlpiPlugin\Grcmanager\Install\AdSecurityAuditTables as Tables;
use GlpiPlugin\Grcmanager\Nonconformity;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\RiskIndicator;
use GlpiPlugin\Grcmanager\Services\Access\PingCastleReport;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #835 : audit de sécurité de l'Active Directory, sur le rapport PingCastle fictif
 * (exemple.test) importé dans une entité de test (seule entité active), et un import d'une entité
 * cachée.
 */
final class AdSecurityAuditTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    private int $hidden;

    private string $domain;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $suffix = (string) random_int(1000, 9999);
        $this->domain = 'ad' . $suffix . '.exemple.test';
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo Annuaire ' . $suffix);
        $this->hidden = $this->createTestEntity(0, 'PhpUnitDemo Filiale annuaire ' . $suffix);
        $_SESSION['glpiactiveentities'] = [$this->entity];
        $_SESSION['glpiactiveentities_string'] = (string) $this->entity;
        $_SESSION['glpiactive_entity'] = $this->entity;
    }

    protected function tearDown(): void
    {
        global $DB;

        $ids = [];
        $mine = ['SELECT' => 'id', 'FROM' => Tables::AUDITS, 'WHERE' => ['domain' => $this->domain]];
        foreach ($DB->request($mine) as $row) {
            $ids[] = (int) $row['id'];
        }
        if ($ids !== []) {
            $DB->delete(Tables::RULES, [AdSecurityAudit::FK => $ids]);
            $DB->delete(Tables::AUDITS, ['id' => $ids]);
        }
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        parent::tearDown();
    }

    /**
     * Rapport d'exemple pour le domaine du test, avec la date et le score donnés ; $drop retire une
     * règle, $add en ajoute une.
     */
    private function report(string $date, int $score, string $drop = '', string $add = ''): string
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/pingcastle-example.xml');
        $xml = str_replace('>exemple.test</DomainFQDN>', '>' . $this->domain . '</DomainFQDN>', $xml);
        $xml = (string) preg_replace('#<GenerationDate>[^<]*<#', '<GenerationDate>' . $date . '<', $xml);
        $xml = (string) preg_replace('#<GlobalScore>\d+<#', '<GlobalScore>' . $score . '<', $xml);
        if ($drop !== '') {
            $xml = (string) preg_replace(
                '#<HealthcheckRiskRule>(?:(?!</HealthcheckRiskRule>).)*<RiskId>' . preg_quote($drop, '#')
                    . '</RiskId>.*?</HealthcheckRiskRule>#s',
                '',
                $xml
            );
        }
        if ($add !== '') {
            $xml = str_replace('</RiskRules>', '<HealthcheckRiskRule><Points>40</Points><Category>Trusts</Category>'
                . '<RiskId>' . $add . '</RiskId><Rationale>Fictitious trust without filtering</Rationale>'
                . '</HealthcheckRiskRule></RiskRules>', $xml);
        }

        return $xml;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function rulesById(int $auditId): array
    {
        $rules = [];
        foreach (AdSecurityAudit::rulesOf($auditId) as $rule) {
            $rules[(string) $rule['rule_id']] = $rule;
        }

        return $rules;
    }

    public function testImportHistoryAndComparison(): void
    {
        $first = AdSecurityAudit::import($this->report('2026-01-10T09:00:00', 55), 'ad_hc_a.xml', false);
        $this->assertSame('', $first['error']);
        $this->assertGreaterThan(0, $first['id']);
        $this->assertSame(6, $first['rules']);
        $this->assertFalse($first['secrets']);

        $again = AdSecurityAudit::import($this->report('2026-01-10T09:00:00', 55), 'ad_hc_a.xml', false);
        $this->assertSame('duplicate', $again['error'], 'Le même fichier n\'est importé qu\'une fois.');

        $second = AdSecurityAudit::import(
            $this->report('2026-04-10T09:00:00', 40, 'S-Inactive', 'T-SIDFiltering'),
            'ad_hc_b.xml',
            false
        );
        $this->assertSame('', $second['error']);

        $audit = AdSecurityAudit::getAudit($second['id']);
        $this->assertNotNull($audit);
        $this->assertSame($this->entity, (int) $audit['entities_id']);
        $this->assertSame($this->domain, $audit['domain']);
        $previous = AdSecurityAudit::previousAudit($audit);
        $this->assertNotNull($previous);
        $this->assertSame($first['id'], (int) $previous['id']);

        $detail = AdSecurityAudit::detail($audit);
        $this->assertSame(-15, $detail['audit']['trend']);
        $this->assertSame(1, $detail['new']);
        $this->assertSame(['S-Inactive'], array_column($detail['resolved'], 'rule_id'));
        $new = array_values(array_filter($detail['rows'], static fn (array $r): bool => $r['is_new']));
        $this->assertSame('T-SIDFiltering', $new[0]['rule_id']);
        $this->assertSame('high', $new[0]['severity']);
        $this->assertSame('P-Delegated', $detail['rows'][0]['rule_id'], 'Règles par points décroissants.');

        $mine = array_values(array_filter(
            AdSecurityAudit::latestByDomain(AdSecurityAudit::audits()),
            fn (array $a): bool => $a['domain'] === $this->domain
        ));
        $this->assertCount(1, $mine);
        $this->assertSame($second['id'], (int) $mine[0]['id']);
        $this->assertSame(40.0, AdSecurityAudit::worstScore([$this->entity]));
        $this->assertNull(AdSecurityAudit::worstScore([]));

        $page = AdSecurityAudit::pageData($first['id']);
        $this->assertSame($first['id'], $page['detail']['audit']['id']);
        $this->assertNull($page['detail']['previous']);
        $this->assertSame(55, $page['detail']['audit']['scores']['privileged']);
        $this->assertSame('poor', $page['detail']['audit']['levels']['privileged']);
    }

    public function testTrappedOrForeignFilesStoreNothing(): void
    {
        $before = countElementsInTable(Tables::AUDITS);
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
            . '<HealthcheckData><GlobalScore>1</GlobalScore><DomainFQDN>&e;</DomainFQDN></HealthcheckData>';
        $this->assertSame(PingCastleReport::ERROR_DOCTYPE, AdSecurityAudit::import($xxe, 'x.xml', true)['error']);
        $this->assertSame(
            PingCastleReport::ERROR_NOT_PINGCASTLE,
            AdSecurityAudit::import('<pfsense><filter/></pfsense>', 'config.xml', true)['error']
        );
        $this->assertSame(
            'no_domain',
            AdSecurityAudit::import(
                '<HealthcheckData><GlobalScore>1</GlobalScore></HealthcheckData>',
                'x.xml',
                false
            )['error']
        );
        $this->assertSame($before, countElementsInTable(Tables::AUDITS));
    }

    public function testSecretsAreNotKeptAndTheArchiveIsRestricted(): void
    {
        $xml = str_replace(
            '</RiskRules>',
            '</RiskRules><GPPPassword><GPPPassword><UserName>svc-fictif</UserName>'
                . '<Password>fictitious-value-835</Password></GPPPassword></GPPPassword>',
            $this->report('2026-02-01T08:00:00', 60)
        );
        $result = AdSecurityAudit::import($xml, 'ad_hc_secret.xml', true);
        $this->assertSame('', $result['error']);
        $this->assertTrue($result['secrets']);
        $audit = AdSecurityAudit::getAudit($result['id']);
        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit['secrets_found']);
        $stored = json_encode([$audit, AdSecurityAudit::rulesOf($result['id'])]);
        $this->assertStringNotContainsString('fictitious-value-835', (string) $stored);

        if ($result['archived']) {
            $documentsId = (int) $audit['documents_id'];
            $this->assertGreaterThan(0, $documentsId);
            $this->assertSame(
                ['itemtype' => AdSecurityAudit::class, 'items_id' => $result['id']],
                RestrictedDocument::sourceOf($documentsId)
            );
            $this->assertTrue(RestrictedDocument::canReadSource($documentsId));
            $document = new \Document();
            $this->assertTrue($document->getFromDB($documentsId));
            $content = (string) file_get_contents(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
            $this->assertStringNotContainsString('fictitious-value-835', $content, 'Archive nettoyée.');
            $this->assertStringContainsString('[removed]', $content);
        }

        $this->assertTrue(AdSecurityAudit::clear($result['id']));
        $this->assertNull(AdSecurityAudit::getAudit($result['id']));
        $this->assertSame(0, countElementsInTable(Tables::RULES, [AdSecurityAudit::FK => $result['id']]));
    }

    public function testActionIsProposedLinkedAndCarriedToTheNextReport(): void
    {
        $first = AdSecurityAudit::import($this->report('2026-01-10T09:00:00', 55), 'ad_hc_a.xml', false);
        $rule = self::rulesById($first['id'])['P-Delegated'];

        $draft = AdSecurityAudit::findingDraft((int) $rule['id']);
        $this->assertNotNull($draft);
        $this->assertSame('nonconformity', $draft['finding_type']);
        $this->assertSame('major', $draft['severity']);
        $this->assertSame('A.5.15, A.8.2', $draft['requirement_reference']);
        $this->assertSame($this->entity, $draft['entities_id']);
        $this->assertStringContainsString($this->domain, $draft['title']);
        $this->assertStringContainsString('Administrator accounts that can be delegated', $draft['description']);

        $finding = new Nonconformity();
        $findingId = (int) $finding->add([
            'title' => $draft['title'], 'description' => $draft['description'], 'finding_type' => 'nonconformity',
            'severity' => 'major', 'entities_id' => $this->entity,
            '_grcmanager_adauditrule' => (int) $rule['id'],
        ]);
        $this->assertGreaterThan(0, $findingId);
        $linked = self::rulesById($first['id'])['P-Delegated'];
        $this->assertSame($findingId, (int) $linked['plugin_grcmanager_nonconformities_id']);
        $this->assertNull(AdSecurityAudit::findingDraft((int) $rule['id']), 'Déjà une action.');

        $ticketId = AdSecurityAudit::createTicket((int) $rule['id']);
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame(0, AdSecurityAudit::createTicket((int) $rule['id']), 'Un seul ticket par règle.');
        $ticket = new \Ticket();
        $this->assertTrue($ticket->getFromDB($ticketId));
        $this->assertSame($this->entity, (int) $ticket->fields['entities_id']);
        $this->assertStringContainsString('P-Delegated', (string) $ticket->fields['name']);

        $second = AdSecurityAudit::import($this->report('2026-04-10T09:00:00', 50), 'ad_hc_b.xml', false);
        $carried = self::rulesById($second['id'])['P-Delegated'];
        $this->assertSame($findingId, (int) $carried['plugin_grcmanager_nonconformities_id'], 'Lien repris.');
        $this->assertSame($ticketId, (int) $carried['tickets_id']);

        $finding->delete(['id' => $findingId], true);
        $unlinked = self::rulesById($second['id'])['P-Delegated'];
        $this->assertSame(0, (int) $unlinked['plugin_grcmanager_nonconformities_id']);
        $ticket->delete(['id' => $ticketId], true);
    }

    public function testAnotherEntityIsHiddenAndNotLinked(): void
    {
        global $DB;

        $DB->insert(Tables::AUDITS, [
            'entities_id' => $this->hidden, 'tool' => 'pingcastle', 'domain' => $this->domain, 'global_score' => 90,
            'report_date' => '2026-05-01 10:00:00', 'file_sha256' => str_repeat('a', 64),
        ]);
        $hiddenId = (int) $DB->insertId();
        $DB->insert(Tables::RULES, [
            AdSecurityAudit::FK => $hiddenId, 'rule_id' => 'P-Hidden', 'category' => 'privileged',
        ]);
        $hiddenRule = (int) $DB->insertId();

        $this->assertNull(AdSecurityAudit::getAudit($hiddenId));
        $this->assertFalse(AdSecurityAudit::canReadAudit($hiddenId));
        $this->assertNotContains($hiddenId, array_map('intval', array_column(AdSecurityAudit::audits(), 'id')));
        $this->assertNull(AdSecurityAudit::findingDraft($hiddenRule));
        $this->assertSame(0, AdSecurityAudit::createTicket($hiddenRule));
        $this->assertFalse(AdSecurityAudit::clear($hiddenId));
        $this->assertSame(90.0, AdSecurityAudit::worstScore([$this->hidden]), 'Le KRI mesure son propre périmètre.');

        $first = AdSecurityAudit::import($this->report('2026-01-10T09:00:00', 55), 'ad_hc_a.xml', false);
        $rule = self::rulesById($first['id'])['S-Inactive'];
        $finding = new Nonconformity();
        $findingId = (int) $finding->add([
            'title' => 'PhpUnitDemo action filiale', 'entities_id' => $this->hidden,
            '_grcmanager_adauditrule' => (int) $rule['id'],
        ]);
        if ($findingId > 0) {
            $kept = self::rulesById($first['id'])['S-Inactive'];
            $this->assertSame(0, (int) $kept['plugin_grcmanager_nonconformities_id']);
            $finding->delete(['id' => $findingId], true);
        }
    }

    public function testEvidenceDashboardReadinessAndKri(): void
    {
        $this->assertNull(AdSecurityAudit::evidence('A.8.2'), 'Sans import, pas de preuve.');
        AdSecurityAudit::import($this->report('2026-01-10T09:00:00', 55), 'ad_hc_a.xml', false);

        $evidence = AdSecurityAudit::evidence('A.8.2');
        $this->assertNotNull($evidence);
        $mine = array_values(array_filter($evidence['domains'], fn (array $d): bool => $d['domain'] === $this->domain));
        $this->assertSame(55, $mine[0]['score']);
        $this->assertSame('poor', $mine[0]['level']);
        $this->assertSame(2, $mine[0]['rules'], 'P-Delegated et P-Kerberoasting concernent A.8.2.');
        $this->assertNotNull(AdSecurityAudit::evidence('A.5.18'));
        $this->assertNull(AdSecurityAudit::evidence('A.5.1'));

        $card = SecuredDashboardCards::adSecurityScore();
        $this->assertSame(55, $card['number']);
        $this->assertStringContainsString('adsecurityaudit.php', $card['url']);
        $this->assertStringContainsString($this->domain, (string) AdSecurityAudit::readinessFact());

        $this->assertArrayHasKey('ad_security_score', RiskIndicator::SOURCES);
        $this->assertArrayHasKey('ad_security_score', RiskIndicator::getSources());
        $kri = new RiskIndicator();
        $kri->fields = ['source' => 'ad_security_score', 'entities_id' => $this->entity];
        $this->assertSame(55.0, $kri->computeSourceValue());
    }
}
