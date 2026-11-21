<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\AccessReviewSuggestion;
use Profile_User;

/**
 * Issue #866 : revue des accès pré-remplie par des règles. Chaque compte arrive avec une suggestion
 * (garder, retirer, à examiner) et ses raisons ; la décision reste celle du relecteur.
 */
final class AccessReviewSuggestionTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    /**
     * @param array<string, mixed> $extra champs de la campagne
     */
    private function createCampaign(array $extra = []): AccessReview
    {
        global $DB;

        $entityId = $this->createTestEntity(0, 'PHPUnit Revue suggestions');
        $chief = $this->createTestUser('Claire', 'Chef');
        $leaver = $this->createTestUser('Paul', 'Partant', ['users_id_supervisor' => $chief]);
        $steady = $this->createTestUser('Sarah', 'Stable', ['users_id_supervisor' => $chief]);
        $admin = $this->createTestUser('Adam', 'Admin', ['users_id_supervisor' => $chief]);
        // Fin de compte passée : la suggestion est « retirer ».
        $DB->update('glpi_users', ['end_date' => date('Y-m-d H:i:s', strtotime('-3 days'))], ['id' => $leaver]);
        $DB->update('glpi_users', ['last_login' => date('Y-m-d H:i:s', strtotime('-2 days'))], ['id' => $steady]);
        $DB->update('glpi_users', ['last_login' => date('Y-m-d H:i:s', strtotime('-1 day'))], ['id' => $admin]);
        foreach ([[$leaver, 2], [$steady, 2], [$admin, 4]] as [$usersId, $profileId]) {
            $DB->insert(Profile_User::getTable(), [
                'users_id'     => $usersId,
                'profiles_id'  => $profileId,
                'entities_id'  => $entityId,
                'is_recursive' => 0,
            ]);
        }
        $this->ids = compact('entityId', 'chief', 'leaver', 'steady', 'admin');

        $campaign = new AccessReview();
        $id = (int) $campaign->add($extra + [
            'name'         => 'PHPUnit revue suggestions',
            'entities_id'  => $entityId,
            'is_recursive' => 0,
            'frequency'    => 'annual',
        ]);
        $this->assertGreaterThan(0, $id);
        $campaign->getFromDB($id);

        return $campaign;
    }

    /**
     * @return array<int, array<string, mixed>> users_id => ligne
     */
    private function linesByUser(int $campaignId): array
    {
        $lines = [];
        foreach (AccessReview::getLines($campaignId) as $line) {
            $lines[$line['users_id']] = $line;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $line
     * @return list<string>
     */
    private static function reasonCodes(array $line): array
    {
        return array_column(json_decode((string) $line['suggestion_reasons'], true) ?: [], 'code');
    }

    public function testLaunchFillsEachAccountWithASuggestionAndItsReason(): void
    {
        $campaign = $this->createCampaign();
        $this->assertSame(\GlpiPlugin\Grcmanager\Services\Access\AccessReviewSuggestionRules::defaults(), AccessReviewSuggestion::settings($campaign->fields));

        $this->assertSame(3, $campaign->launch());

        $lines = $this->linesByUser($campaign->getID());
        $leaver = $lines[$this->ids['leaver']];
        $this->assertSame('remove', $leaver['suggestion']);
        $this->assertSame(['expired'], self::reasonCodes($leaver));
        $this->assertSame('remove', $leaver['suggested_decision']);
        $this->assertTrue($leaver['suggestion_reasons_list'][0]['removal']);

        $this->assertSame('keep', $lines[$this->ids['steady']]['suggestion']);
        $this->assertSame(['no_signal'], self::reasonCodes($lines[$this->ids['steady']]));

        // Super-Admin sans double authentification ni import MFA : à examiner, la raison le dit.
        $admin = $lines[$this->ids['admin']];
        $this->assertSame('examine', $admin['suggestion']);
        $this->assertSame(['privileged_no_mfa'], self::reasonCodes($admin));
        $this->assertSame('', $admin['suggested_decision'], 'aucune décision mise en avant pour « à examiner »');
        $this->assertNotSame('', (string) $admin['date_suggestion']);

        // La suggestion ne décide rien : toutes les lignes restent à revoir.
        $this->assertSame(['pending'], array_values(array_unique(array_column($lines, 'decision'))));
    }

    public function testKeepingAnAccountSuggestedForRemovalNeedsAComment(): void
    {
        $campaign = $this->createCampaign();
        $campaign->launch();
        $lineId = $this->linesByUser($campaign->getID())[$this->ids['leaver']]['id'];

        $this->assertNotSame('', AccessReview::decide($lineId, 'keep', '', $this->ids['chief']));
        $this->assertSame('pending', $this->linesByUser($campaign->getID())[$this->ids['leaver']]['decision']);

        $this->assertSame('', AccessReview::decide($lineId, 'keep', 'Contrat prolongé de trois mois', $this->ids['chief']));
        $line = $this->linesByUser($campaign->getID())[$this->ids['leaver']];
        $this->assertSame('keep', $line['decision']);
        $this->assertTrue($line['differs'], 'décision opposée à la suggestion, signalée');

        $steady = $this->linesByUser($campaign->getID())[$this->ids['steady']]['id'];
        $this->assertSame('', AccessReview::decide($steady, 'keep', '', $this->ids['chief']));
        $this->assertFalse($this->linesByUser($campaign->getID())[$this->ids['steady']]['differs']);
    }

    public function testRulesAreSetOnTheCampaignAndRefreshOnlyTouchesLinesStillToReview(): void
    {
        global $DB;

        $campaign = $this->createCampaign([
            '_suggestion_rules_form' => 1,
            'suggestion_rules'       => ['inactive_days' => 45, 'leaving_days' => 15, 'privileged' => 1],
        ]);
        $this->assertSame(
            ['inactive_days' => 90, 'leaving_days' => 15, 'privileged' => true, 'mfa' => false, 'group_change' => false],
            AccessReviewSuggestion::settings($campaign->fields),
            'durée inconnue ramenée à la valeur par défaut, cases décochées à faux'
        );
        $campaign->launch();
        $this->assertSame(['privileged'], self::reasonCodes($this->linesByUser($campaign->getID())[$this->ids['admin']]));
        $leaverLine = $this->linesByUser($campaign->getID())[$this->ids['leaver']]['id'];
        AccessReview::decide($leaverLine, 'remove', 'Parti', $this->ids['chief']);

        // Règle des profils à privilèges désactivée, puis recalcul.
        $this->assertTrue($campaign->update([
            'id'                     => $campaign->getID(),
            '_suggestion_rules_form' => 1,
            'suggestion_rules'       => ['inactive_days' => 90, 'leaving_days' => 30],
        ]));
        $campaign->getFromDB($campaign->getID());
        $this->assertFalse(AccessReviewSuggestion::settings($campaign->fields)['privileged']);
        // Un autre enregistrement sans le formulaire ne touche pas aux règles.
        $campaign->update(['id' => $campaign->getID(), 'comment' => 'PHPUnit', 'suggestion_rules' => '{}']);
        $campaign->getFromDB($campaign->getID());
        $this->assertFalse(AccessReviewSuggestion::settings($campaign->fields)['privileged']);

        $DB->update('glpi_users', ['end_date' => null], ['id' => $this->ids['leaver']]);
        $this->assertSame(2, AccessReviewSuggestion::refresh($campaign), 'deux lignes encore à revoir');

        $lines = $this->linesByUser($campaign->getID());
        $this->assertSame('keep', $lines[$this->ids['admin']]['suggestion']);
        $this->assertSame('remove', $lines[$this->ids['leaver']]['suggestion'], 'ligne décidée : suggestion vue gardée');

        // La campagne suivante reprend les règles.
        $campaign->close();
        $next = new AccessReview();
        $next->getFromDB($campaign->prepareNext());
        $this->assertSame(AccessReviewSuggestion::settings($campaign->fields), AccessReviewSuggestion::settings($next->fields));
    }

    public function testTheMigrationCanBeReplayed(): void
    {
        global $DB;

        \GlpiPlugin\Grcmanager\Install\AccessReviewSuggestionFields::install(new \Migration(PLUGIN_GRCMANAGER_VERSION));
        \GlpiPlugin\Grcmanager\Install\AccessReviewSuggestionFields::install(new \Migration(PLUGIN_GRCMANAGER_VERSION));

        foreach (['suggestion', 'suggestion_reasons', 'date_suggestion'] as $field) {
            $this->assertTrue($DB->fieldExists(AccessReview::LINES_TABLE, $field));
        }
        $this->assertTrue($DB->fieldExists(AccessReview::getTable(), 'suggestion_rules'));
    }
}
