<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Feature\MfaCoverageFeature;
use GlpiPlugin\Grcmanager\PageHelp;
use GlpiPlugin\Grcmanager\RiskIndicator;

/**
 * Câblage des fonctionnalités découvertes (src/Feature/) dans les listes communes : la couverture MFA
 * (#852), première à passer par là, garde sa place dans le catalogue des cartes et dans les sources
 * de KRI, et son cadre de preuve s'affiche sur les mesures A.8.5 et A.5.17.
 */
final class FeatureRegistryTest extends GrcmanagerIntegrationTestCase
{
    public function testDashboardCardKeepsItsPlace(): void
    {
        require_once dirname(__DIR__, 2) . '/hook.php';
        $keys = array_keys(FeatureRegistry::dashboardCards([
            'grcmanager_ad_security_score' => ['label' => 'x'],
            'grcmanager_patch_compliance_rate' => ['label' => 'y'],
        ], 'GRC Manager'));
        $this->assertSame(
            ['grcmanager_ad_security_score', 'grcmanager_mfa_admin_rate', 'grcmanager_patch_compliance_rate'],
            array_slice($keys, 0, 3)
        );
        $catalog = array_keys(plugin_grcmanager_dashboard_cards());
        $position = array_search('grcmanager_mfa_admin_rate', $catalog, true);
        $this->assertNotFalse($position);
        $this->assertSame('grcmanager_ad_security_score', $catalog[$position - 1]);
    }

    public function testRiskIndicatorSourceKeepsItsPlaceAndComputes(): void
    {
        $keys = array_keys(RiskIndicator::getSources());
        $position = array_search('admins_without_mfa', $keys, true);
        $this->assertNotFalse($position);
        $this->assertSame('unencrypted_laptops', $keys[$position - 1]);
        $this->assertSame('late_patch_critical_servers', $keys[$position + 1]);
        $this->assertSame(array_keys(RiskIndicator::sourceDefinitions()), array_slice($keys, 1));
        $definition = RiskIndicator::sourceDefinitions()['admins_without_mfa'];
        $this->assertSame(['user' => READ], $definition['rights']);

        $kri = new RiskIndicator();
        $kri->fields = ['source' => 'admins_without_mfa', 'entities_id' => 0];
        $this->assertIsFloat($kri->computeSourceValue());
    }

    public function testControlEvidenceBoxRenders(): void
    {
        $boxes = FeatureRegistry::controlEvidence('A.8.5');
        $mfa = array_values(array_filter(
            $boxes,
            static fn (array $box): bool => $box['template'] === MfaCoverageFeature::controlEvidenceTemplate()
        ));
        $this->assertCount(1, $mfa);
        $html = TemplateRenderer::getInstance()->render($mfa[0]['template'], ['evidence' => $mfa[0]['data']]);
        $this->assertStringContainsString('grc-mfa-coverage-evidence', $html);
        $this->assertSame([], array_filter(
            FeatureRegistry::controlEvidence('A.5.1'),
            static fn (array $box): bool => $box['template'] === MfaCoverageFeature::controlEvidenceTemplate()
        ));
    }

    public function testPageHelpComesFromTheFeature(): void
    {
        $this->assertArrayHasKey('mfacoverage', FeatureRegistry::pageHelp());
        $this->assertSame(FeatureRegistry::pageHelp()['mfacoverage'], PageHelp::texts()['mfacoverage']);
        $this->assertArrayHasKey('dashboard', PageHelp::texts());
    }
}
