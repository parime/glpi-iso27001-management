<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Install\PolicyCoverageTables;
use GlpiPlugin\Grcmanager\Install\PolicyTemplateTables;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyCoverage;
use GlpiPlugin\Grcmanager\PolicyTemplate;

/**
 * Issue #545 : import d'un modèle de politique. Chaque import crée une nouvelle politique en
 * brouillon, variables remplacées, reliée section par section aux mesures de la SoA ; aucune
 * politique existante n'est remplacée et rien n'est approuvé.
 */
final class PolicyTemplateTest extends GrcmanagerIntegrationTestCase
{
    public function testImportCreatesDraftLinkedBySectionWithoutOverwriting(): void
    {
        global $DB;

        $this->assertTrue($DB->tableExists(PolicyTemplateTables::IMPORTS));
        $codes = EntityPerimeter::controlIdsByCode();
        $this->assertArrayHasKey('A.5.15', $codes, 'SoA initialisée');

        $values = ['organisation' => 'Fictive Org 545', 'ciso_users_id' => 2, 'review_months' => 6];
        $first = PolicyTemplate::import(['access_control', 'unknown'], 'en', $values);
        $this->assertCount(1, $first['created']);
        $policy = new Policy();
        $this->assertTrue($policy->getFromDB($first['created'][0]));
        $this->assertSame('draft', $policy->fields['status']);
        $this->assertSame('Access control policy', $policy->fields['title']);
        $this->assertNull($policy->fields['approval_date']);
        $this->assertSame(2, (int) $policy->fields['users_id']);
        $description = (string) $policy->fields['description'];
        $this->assertStringContainsString('Fictive Org 545', $description);
        $this->assertStringContainsString('every 6 months', $description);
        $this->assertStringNotContainsString('{organisation}', $description);

        $links = PolicyCoverage::policyLinks($first['created'][0]);
        $this->assertSame($first['links'], count($links));
        $this->assertGreaterThanOrEqual(8, count($links));
        $sections = array_column($links, 'section');
        $this->assertContains('4. Authentication', $sections);
        $this->assertContains('6. Approval, review and exceptions', $sections);

        $second = PolicyTemplate::import(['access_control'], 'fr', $values);
        $this->assertCount(1, $second['created']);
        $this->assertNotSame($first['created'][0], $second['created'][0], 'jamais d\'écrasement');
        $this->assertTrue($policy->getFromDB($first['created'][0]), 'la première politique reste');
        $this->assertTrue($policy->getFromDB($second['created'][0]));
        $this->assertSame('Politique de contrôle d\'accès', $policy->fields['title']);

        $imported = PolicyTemplate::importedPolicies();
        $this->assertArrayHasKey($first['created'][0], $imported['access_control']);
        $this->assertArrayHasKey($second['created'][0], $imported['access_control']);

        foreach (array_merge($first['created'], $second['created']) as $id) {
            $this->assertTrue((new Policy())->delete(['id' => $id], true));
            $where = ['plugin_grcmanager_policies_id' => $id];
            $this->assertSame(0, countElementsInTable(PolicyCoverageTables::LINKS, $where), 'liens purgés');
            $this->assertSame(0, countElementsInTable(PolicyTemplateTables::IMPORTS, $where), 'trace purgée');
        }
    }

    public function testEveryTemplateImports(): void
    {
        global $DB;

        $result = PolicyTemplate::import(
            ['isms', 'asset_management', 'backup', 'logging', 'incident_management', 'supplier', 'teleworking',
                'secure_development', 'cryptography', 'continuity', 'classification', 'access_control'],
            'en',
            ['organisation' => '', 'ciso_users_id' => 0]
        );
        $this->assertCount(12, $result['created']);
        $this->assertSame([], $result['unknown']);
        $policy = new Policy();
        $this->assertTrue($policy->getFromDB($result['created'][0]));
        $this->assertStringContainsString('[organisation name]', (string) $policy->fields['description']);
        foreach ($result['created'] as $id) {
            $DB->delete(PolicyCoverageTables::LINKS, ['plugin_grcmanager_policies_id' => $id]);
            $DB->delete(PolicyTemplateTables::IMPORTS, ['plugin_grcmanager_policies_id' => $id]);
            $DB->delete(Policy::getTable(), ['id' => $id]);
        }
    }
}
