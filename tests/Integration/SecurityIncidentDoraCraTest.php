<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use CommonITILActor;
use CronTask;
use GlpiPlugin\Grcmanager\SecurityIncidentPostMortem;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecurityIncidentCardProvider;
use GlpiPlugin\Grcmanager\Services\Incident\CraReportingConfig;
use GlpiPlugin\Grcmanager\Services\Incident\PostMortem;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use ITILFollowup;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #600 : délais de notification DORA (incident TIC majeur) et CRA (produit comportant des
 * éléments numériques), à côté de NIS2 et du RGPD. Assertions sur les noms de champs et les clés,
 * jamais sur les libellés traduits (CI en anglais, instance de développement en français).
 */
final class SecurityIncidentDoraCraTest extends GrcmanagerIntegrationTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        // Réglages annulés avec la transaction : les caches statiques aussi.
        FrameworkConfig::reset();
        CraReportingConfig::reset();
    }

    private function regimes(bool $dora, bool $cra): void
    {
        FrameworkConfig::setEnabled(FrameworkSelection::DORA, $dora);
        FrameworkConfig::reset();
        CraReportingConfig::save($cra);
        CraReportingConfig::reset();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function incident(array $fields, string $detected = '-20 hours'): PluginGrcmanagerSecurityIncident
    {
        global $DB;

        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add($fields + ['name' => 'PHPUnit DORA CRA', 'content' => 'x', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $id);
        $DB->update(
            PluginGrcmanagerSecurityIncident::getTable(),
            ['date' => date('Y-m-d H:i:s', (int) strtotime($detected))],
            ['id' => $id]
        );
        $incident->getFromDB($id);

        return $incident;
    }

    private function section(PluginGrcmanagerSecurityIncident $incident): string
    {
        ob_start();
        plugin_grcmanager_post_itil_info_section(['item' => $incident]);

        return (string) ob_get_clean();
    }

    private function header(PluginGrcmanagerSecurityIncident $incident): string
    {
        ob_start();
        plugin_grcmanager_pre_itil_info_section(['item' => $incident]);

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private function keys(PluginGrcmanagerSecurityIncident $incident): array
    {
        return array_column(
            RegulatoryDeadlines::compute(PluginGrcmanagerSecurityIncident::regulatoryFields($incident->fields)),
            'key'
        );
    }

    public function testDoraIsHiddenWhileItsFrameworkIsDisabled(): void
    {
        $this->regimes(false, false);
        $incident = $this->incident(['dora_major' => 1]);

        $this->assertSame(1, (int) $incident->fields['dora_major'], 'la case est enregistrée telle quelle');
        $this->assertSame([], $this->keys($incident));
        $html = $this->section($incident);
        $this->assertStringNotContainsString('name="dora_major"', $html);
        $this->assertStringNotContainsString('name="dora_initial_at"', $html);
        $this->assertStringNotContainsString('name="cra_report_type"', $html);

        $this->regimes(true, false);
        $this->assertSame(['dora_initial', 'dora_intermediate', 'dora_final'], $this->keys($incident));
        $html = $this->section($incident);
        $this->assertStringContainsString('name="dora_major"', $html);
        $this->assertStringContainsString('name="dora_classified_at"', $html);
        $this->assertStringContainsString('name="dora_initial_at"', $html);
        $this->assertStringContainsString('name="dora_final_at"', $html);
        $this->assertStringContainsString(
            PluginGrcmanagerSecurityIncident::getRegulatoryLabels()['dora_initial'],
            $this->header($incident),
            'bandeau en tête de l\'incident'
        );
    }

    public function testDoraClassificationAndNotificationDatesAreSaved(): void
    {
        $this->regimes(true, false);
        $incident = $this->incident(['dora_major' => 'on']);
        $id = (int) $incident->getID();
        $status = (int) $incident->fields['status'];

        $this->assertTrue($incident->update([
            'id'                 => $id,
            'dora_classified_at' => date('Y-m-d\TH:i', (int) strtotime('-19 hours')),
            'dora_initial_at'    => date('Y-m-d\TH:i', (int) strtotime('-18 hours')),
        ]));
        $incident->getFromDB($id);

        $this->assertSame(1, (int) $incident->fields['dora_major']);
        $this->assertNotNull($incident->fields['dora_classified_at']);
        $rows = array_column(
            RegulatoryDeadlines::compute(PluginGrcmanagerSecurityIncident::regulatoryFields($incident->fields)),
            null,
            'key'
        );
        $this->assertSame(RegulatoryDeadlines::STATUS_DONE, $rows['dora_initial']['status']);
        $this->assertSame(
            date('Y-m-d H:i:00', (int) strtotime('-18 hours') + 72 * 3600),
            $rows['dora_intermediate']['due'],
            '72 h après la notification initiale saisie'
        );
        $this->assertSame($status, (int) $incident->fields['status'], 'statut inchangé');

        $incident->update(['id' => $id, 'dora_initial_at' => '']);
        $incident->getFromDB($id);
        $this->assertNull($incident->fields['dora_initial_at'], 'date effacée');
    }

    public function testCraOptionTypeProductAndCorrectiveMeasure(): void
    {
        $this->regimes(false, true);
        $incident = $this->incident(['cra_report_type' => 'unknown']);
        $id = (int) $incident->getID();
        $this->assertSame('', $incident->fields['cra_report_type'], 'énumération fermée');
        $this->assertSame([], $this->keys($incident));

        $incident->update([
            'id'              => $id,
            'cra_report_type' => RegulatoryDeadlines::CRA_VULNERABILITY,
            'cra_product'     => '  Routeur fictif RX-100  ',
        ]);
        $incident->getFromDB($id);
        $this->assertSame('Routeur fictif RX-100', $incident->fields['cra_product']);
        $rows = array_column(
            RegulatoryDeadlines::compute(PluginGrcmanagerSecurityIncident::regulatoryFields($incident->fields)),
            null,
            'key'
        );
        $this->assertSame(RegulatoryDeadlines::STATUS_WAITING, $rows['cra_final_report']['status']);
        $html = $this->section($incident);
        $this->assertStringContainsString('name="cra_fix_available_at"', $html);
        $this->assertStringContainsString('name="cra_final_report_at"', $html);
        $this->assertStringContainsString('ti-hourglass', $html, 'badge « en attente »');

        $incident->update(['id' => $id, 'cra_fix_available_at' => date('Y-m-d\TH:i', (int) strtotime('-1 hour'))]);
        $incident->getFromDB($id);
        $rows = array_column(
            RegulatoryDeadlines::compute(PluginGrcmanagerSecurityIncident::regulatoryFields($incident->fields)),
            null,
            'key'
        );
        $this->assertSame(RegulatoryDeadlines::STATUS_PENDING, $rows['cra_final_report']['status']);

        $this->regimes(false, false);
        $this->assertStringNotContainsString('name="cra_report_type"', $this->section($incident));
        $this->assertSame([], $this->keys($incident), 'option désactivée : plus d\'échéance CRA');
    }

    public function testCronAlertsDoraLikeNis2AndOnlyWhenEnabled(): void
    {
        $this->regimes(false, false);
        $incident = $this->incident(['dora_major' => 1]);
        $id = (int) $incident->getID();
        $count = static fn (): int => countElementsInTable(
            ITILFollowup::getTable(),
            ['itemtype' => PluginGrcmanagerSecurityIncident::class, 'items_id' => $id]
        );
        $before = $count();

        PluginGrcmanagerSecurityIncident::cronRegulatorydeadlines(new CronTask());
        $this->assertSame($before, $count(), 'DORA désactivé : aucune alerte');

        $this->regimes(true, false);
        PluginGrcmanagerSecurityIncident::cronRegulatorydeadlines(new CronTask());
        PluginGrcmanagerSecurityIncident::cronRegulatorydeadlines(new CronTask());
        $this->assertSame($before + 1, $count(), 'une seule alerte « proche »');
        $incident->getFromDB($id);
        $alerts = json_decode((string) $incident->fields['regulatory_alerts'], true);
        $this->assertSame(['dora_initial:due_soon'], $alerts);
        $this->assertSame(1, (int) $incident->fields['dora_major']);
    }

    public function testDashboardMySpaceAndPostMortemListTheNewDeadlines(): void
    {
        $this->regimes(true, true);
        $before = SecurityIncidentCardProvider::regulatoryDeadlinesAtRisk();
        $incident = $this->incident([
            'dora_major'         => 1,
            'cra_report_type'    => RegulatoryDeadlines::CRA_INCIDENT,
            '_users_id_assign'   => (int) $_SESSION['glpiID'],
        ]);
        $id = (int) $incident->getID();
        $this->assertTrue($incident->isUser(CommonITILActor::ASSIGN, (int) $_SESSION['glpiID']));

        // Détecté il y a 20 h : DORA initiale et CRA alerte précoce proches.
        $this->assertSame($before + 2, SecurityIncidentCardProvider::regulatoryDeadlinesAtRisk());

        $mine = array_filter(
            MySpaceProvider::items((int) $_SESSION['glpiID'], date('Y-m-d')),
            static fn (array $item): bool => $item['source'] === 'regulatory_notification' && $item['id'] === $id
        );
        $this->assertCount(6, $mine, 'une ligne par échéance datée');

        $report = SecurityIncidentPostMortem::collect($incident);
        $this->assertCount(6, $report['deadlines'], 'toutes les notifications, tenues ou non');
        $this->assertSame(
            [PostMortem::DEADLINE_MISSING],
            array_values(array_unique(array_column($report['deadlines'], 'outcome')))
        );
    }

    public function testIncidentWithoutAnyBoxIsUnchanged(): void
    {
        $this->regimes(true, true);
        $incident = $this->incident([]);

        $this->assertSame([], $this->keys($incident));
        $this->assertSame([], SecurityIncidentPostMortem::collect($incident)['deadlines']);
        $html = $this->section($incident);
        $this->assertStringNotContainsString('name="dora_initial_at"', $html);
        $this->assertStringNotContainsString('name="cra_early_warning_at"', $html);
    }

    public function testReadOnlyUserCannotEditTheDates(): void
    {
        $this->regimes(true, true);
        $incident = $this->incident(['dora_major' => 1, 'cra_report_type' => RegulatoryDeadlines::CRA_INCIDENT]);
        $right = PluginGrcmanagerSecurityIncident::$rightname;
        $saved = $_SESSION['glpiactiveprofile'][$right];
        try {
            $_SESSION['glpiactiveprofile'][$right] = PluginGrcmanagerSecurityIncident::READALL;
            $incident->getFromDB((int) $incident->getID());
            $this->assertFalse($incident->canUpdateItem());
            $html = $this->section($incident);
            $this->assertMatchesRegularExpression('/name="dora_initial_at"[^>]*disabled/s', $html);
            $this->assertMatchesRegularExpression('/name="cra_early_warning_at"[^>]*disabled/s', $html);
        } finally {
            $_SESSION['glpiactiveprofile'][$right] = $saved;
        }
    }
}
