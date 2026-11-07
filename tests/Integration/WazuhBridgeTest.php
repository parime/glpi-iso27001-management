<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidenceProvider;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #368 : sans le plugin Wazuh, le pont se tait (aucune ligne, aucune carte, aucune erreur).
 */
final class WazuhBridgeTest extends GrcmanagerIntegrationTestCase
{
    public function testWithoutTheWazuhPluginNothingIsShown(): void
    {
        if (\Plugin::isPluginActive('wazuh')) {
            $this->markTestSkipped('Le plugin Wazuh est actif sur cette instance.');
        }
        $this->assertFalse(WazuhEvidenceProvider::isAvailable());
        $this->assertNull(WazuhEvidenceProvider::fetchCoverage());
        $this->assertFalse(SecuredDashboardCards::canShow('wazuhCoverage'));
        $catalog = SecuredDashboardCards::filterCatalog([
            'grcmanager_wazuh_coverage' => ['provider' => SecuredDashboardCards::class . '::wazuhCoverage'],
        ]);
        $this->assertSame([], $catalog, 'La carte n\'est pas proposée.');
    }
}
