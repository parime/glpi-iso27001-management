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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use CronTask;
use Document;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Policy\AcknowledgementRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use Group;
use Group_User;
use Html;
use NotificationEvent;
use Preference;
use Profile;
use Profile_User;
use Session;
use User;

/**
 * Issue #535 : prise de connaissance des politiques de sécurité par leur public (A.5.1, A.6.3).
 *
 * Sur une politique approuvée, le responsable définit un public cible (groupes, profils, entités)
 * et un délai de réponse, puis lance une campagne : le public est figé (comptes actifs), chacun est
 * prévenu et confirme depuis ses préférences (onglet « Politiques à lire ») ou « Mon espace GRC ».
 * Une nouvelle version d'une politique déjà diffusée relance une campagne ; la précédente reste en
 * historique, remplacée. Les retardataires sont relancés chaque semaine par une notification, sans
 * autre conséquence. À la clôture, le rapport (taux par entité, retardataires) est archivé comme
 * preuve sur la mesure A.5.1 et sur la politique.
 *
 * Le moteur de campagne de la charte d'assetsign (signature électronique) n'est pas repris : il est
 * propre à la charte et à son plugin ; la prise de connaissance reste autonome dans ce plugin.
 *
 * Les personnes du public n'ont en général aucun droit sur le plugin : elles ne voient que leurs
 * propres lignes, et lisent les documents de la politique par front/policyacknowledgement.send.php.
 * Les gestionnaires (droit de lecture des politiques) ne voient que les lignes des entités de leur
 * session.
 */
class PolicyAcknowledgement extends CommonGLPI
{
    public const AUDIENCE_TABLE = 'glpi_plugin_grcmanager_policyaudiences';
    public const CAMPAIGNS_TABLE = 'glpi_plugin_grcmanager_policycampaigns';
    public const LINES_TABLE = 'glpi_plugin_grcmanager_policycampaigns_users';

    public const REQUEST_EVENT = 'policy_acknowledgement_request';
    public const REMINDER_EVENT = 'policy_acknowledgement_reminder';

    /** Mesure de l'Annexe A où le rapport de clôture est archivé. */
    public const CONTROL_CODE = 'A.5.1';

    public static function getTypeName($nb = 0)
    {
        return __('Policy acknowledgement', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-check';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/policyacknowledgement.form.php';
    }

    /** Onglet « Politiques à lire » des préférences de l'utilisateur connecté. */
    public static function getMineURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/front/preference.php?forcetab=' . rawurlencode(self::class . '$1');
    }

    /** Même lien, absolu (notifications). */
    public static function getMineAbsoluteURL(): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/front/preference.php?forcetab=' . rawurlencode(self::class . '$1');
    }

    public static function getExportURL(int $campaignId, string $format): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/policyacknowledgement.export.php?id='
            . $campaignId . '&format=' . $format;
    }

    public static function getDownloadURL(int $policyId, int $documentsId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/policyacknowledgement.send.php?policies_id='
            . $policyId . '&documents_id=' . $documentsId;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function getAudienceTypes(): array
    {
        return [
            AcknowledgementRules::AUDIENCE_GROUP   => [__('Group', 'grcmanager'), 'ti-users-group'],
            AcknowledgementRules::AUDIENCE_PROFILE => [__('Profile', 'grcmanager'), 'ti-id-badge-2'],
            AcknowledgementRules::AUDIENCE_ENTITY  => [__('Entity', 'grcmanager'), 'ti-building'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Public cible
    // ---------------------------------------------------------------------------------------------

    /**
     * @return list<array{id: int, itemtype: string, items_id: int, is_recursive: int, name: string,
     *     type_label: string, icon: string}>
     */
    public static function getAudience(int $policyId): array
    {
        global $DB;

        $types = self::getAudienceTypes();
        $list = [];
        foreach (
            $DB->request([
                'FROM'  => self::AUDIENCE_TABLE,
                'WHERE' => ['plugin_grcmanager_policies_id' => $policyId],
                'ORDER' => ['itemtype', 'id'],
            ]) as $row
        ) {
            $itemtype = (string) $row['itemtype'];
            if (!AcknowledgementRules::isAudienceType($itemtype)) {
                continue;
            }
            $list[] = [
                'id'           => (int) $row['id'],
                'itemtype'     => $itemtype,
                'items_id'     => (int) $row['items_id'],
                'is_recursive' => (int) $row['is_recursive'],
                'name'         => (string) Dropdown::getDropdownName($itemtype::getTable(), (int) $row['items_id']),
                'type_label'   => $types[$itemtype][0],
                'icon'         => $types[$itemtype][1],
            ];
        }

        return $list;
    }

    public static function hasAudience(int $policyId): bool
    {
        return self::getAudience($policyId) !== [];
    }

    public static function addAudience(int $policyId, string $itemtype, int $itemsId, bool $recursive = false): bool
    {
        global $DB;

        if ($policyId <= 0 || $itemsId < 0 || !AcknowledgementRules::isAudienceType($itemtype)) {
            return false;
        }
        // L'entité racine (0) est une entité valide ; un groupe ou un profil doit exister.
        if (!($itemtype === AcknowledgementRules::AUDIENCE_ENTITY && $itemsId === 0)) {
            $item = new $itemtype();
            if (!$item->getFromDB($itemsId)) {
                return false;
            }
        }
        $where = ['plugin_grcmanager_policies_id' => $policyId, 'itemtype' => $itemtype, 'items_id' => $itemsId];
        $recursive = $itemtype === AcknowledgementRules::AUDIENCE_ENTITY && $recursive ? 1 : 0;
        if (countElementsInTable(self::AUDIENCE_TABLE, $where) > 0) {
            $DB->update(self::AUDIENCE_TABLE, ['is_recursive' => $recursive], $where);

            return true;
        }

        return $DB->insert(self::AUDIENCE_TABLE, $where + [
            'is_recursive'  => $recursive,
            'date_creation' => date('Y-m-d H:i:s'),
        ]) !== false;
    }

    public static function removeAudience(int $policyId, int $audienceId): bool
    {
        global $DB;

        return $DB->delete(self::AUDIENCE_TABLE, [
            'id'                            => $audienceId,
            'plugin_grcmanager_policies_id' => $policyId,
        ]) !== false;
    }

    /**
     * Public cible, entités étendues à leurs sous-entités quand elles sont récursives.
     *
     * @return array{groups: list<int>, profiles: list<int>, entities: list<int>}
     */
    public static function audienceDefinition(int $policyId): array
    {
        $definition = ['groups' => [], 'profiles' => [], 'entities' => []];
        foreach (self::getAudience($policyId) as $row) {
            if ($row['itemtype'] === AcknowledgementRules::AUDIENCE_GROUP) {
                $definition['groups'][] = $row['items_id'];
            } elseif ($row['itemtype'] === AcknowledgementRules::AUDIENCE_PROFILE) {
                $definition['profiles'][] = $row['items_id'];
            } else {
                $entities = $row['is_recursive'] === 1
                    ? array_values(array_map('intval', getSonsOf(Entity::getTable(), $row['items_id'])))
                    : [$row['items_id']];
                $definition['entities'] = array_merge($definition['entities'], $entities);
            }
        }
        $definition['entities'] = array_values(array_unique($definition['entities']));

        return $definition;
    }

    /**
     * Comptes actifs du public cible, avec l'entité où ils sont comptés.
     *
     * @return array<int, int> users_id => entities_id
     */
    public static function resolveMembers(int $policyId): array
    {
        global $DB;

        $definition = self::audienceDefinition($policyId);
        $candidates = [];
        if ($definition['groups'] !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['users_id'],
                    'FROM'   => Group_User::getTable(),
                    'WHERE'  => ['groups_id' => $definition['groups']],
                ]) as $row
            ) {
                $candidates[(int) $row['users_id']] = true;
            }
        }
        $or = [];
        if ($definition['profiles'] !== []) {
            $or[] = ['profiles_id' => $definition['profiles']];
        }
        if ($definition['entities'] !== []) {
            $or[] = ['entities_id' => $definition['entities']];
        }
        if ($or !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['users_id'],
                    'FROM'   => Profile_User::getTable(),
                    'WHERE'  => ['OR' => $or],
                ]) as $row
            ) {
                $candidates[(int) $row['users_id']] = true;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $users = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'entities_id'],
                'FROM'   => User::getTable(),
                'WHERE'  => ['id' => array_keys($candidates), 'is_active' => 1, 'is_deleted' => 0],
            ]) as $row
        ) {
            $users[(int) $row['id']] = [
                'default_entity' => $row['entities_id'] === null ? null : (int) $row['entities_id'],
                'groups'         => [],
                'authorizations' => [],
            ];
        }
        if ($users === []) {
            return [];
        }
        foreach (
            $DB->request([
                'SELECT' => ['users_id', 'groups_id'],
                'FROM'   => Group_User::getTable(),
                'WHERE'  => ['users_id' => array_keys($users)],
            ]) as $row
        ) {
            $users[(int) $row['users_id']]['groups'][] = (int) $row['groups_id'];
        }
        foreach (
            $DB->request([
                'SELECT' => ['users_id', 'profiles_id', 'entities_id'],
                'FROM'   => Profile_User::getTable(),
                'WHERE'  => ['users_id' => array_keys($users)],
                'ORDER'  => ['id'],
            ]) as $row
        ) {
            $users[(int) $row['users_id']]['authorizations'][] = [
                'profiles_id' => (int) $row['profiles_id'],
                'entities_id' => (int) $row['entities_id'],
            ];
        }

        return AcknowledgementRules::members($definition, $users);
    }

    // ---------------------------------------------------------------------------------------------
    // Campagnes
    // ---------------------------------------------------------------------------------------------

    /**
     * Campagnes d'une politique, la plus récente d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function getCampaigns(int $policyId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::CAMPAIGNS_TABLE,
            'WHERE' => ['plugin_grcmanager_policies_id' => $policyId],
            'ORDER' => ['id DESC'],
        ]), false));
    }

    /**
     * Campagne en vigueur : la plus récente. Les précédentes sont remplacées.
     *
     * @return array<string, mixed>|null
     */
    public static function getCurrentCampaign(int $policyId): ?array
    {
        return self::getCampaigns($policyId)[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCampaign(int $campaignId): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::CAMPAIGNS_TABLE, 'WHERE' => ['id' => $campaignId]])->current();

        return $row === null ? null : $row;
    }

    public static function isCurrent(array $campaign): bool
    {
        $current = self::getCurrentCampaign((int) $campaign['plugin_grcmanager_policies_id']);

        return $current !== null && (int) $current['id'] === (int) $campaign['id'];
    }

    /**
     * Lance une campagne pour la version approuvée de la politique : fige le public et le prévient.
     *
     * @return int nombre de personnes du public, -1 si la campagne ne peut pas être lancée
     */
    public static function launch(Policy $policy, int $actorId): int
    {
        global $DB;

        $policyId = $policy->getID();
        $current = self::getCurrentCampaign($policyId);
        $version = (string) ($policy->fields['version'] ?? '');
        if (
            !AcknowledgementRules::canLaunch(
                (string) ($policy->fields['status'] ?? ''),
                self::hasAudience($policyId),
                $current === null ? null : (string) $current['version'],
                $version
            )
        ) {
            return -1;
        }
        $members = self::resolveMembers($policyId);
        if ($members === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $DB->insert(self::CAMPAIGNS_TABLE, [
            'plugin_grcmanager_policies_id' => $policyId,
            'version'                       => $version,
            'status'                        => AcknowledgementRules::STATUS_OPEN,
            'users_id'                      => $actorId,
            'date_launch'                   => $now,
            'due_date'                      => AcknowledgementRules::dueDate(
                $now,
                AcknowledgementRules::sanitizeDays($policy->fields['acknowledgement_days'] ?? null)
            ),
            'date_creation'                 => $now,
            'date_mod'                      => $now,
        ]);
        $campaignId = (int) $DB->insertId();
        foreach ($members as $usersId => $entityId) {
            $DB->insert(self::LINES_TABLE, [
                'plugin_grcmanager_policycampaigns_id' => $campaignId,
                'users_id'                             => $usersId,
                'entities_id'                          => $entityId,
                'date_creation'                        => $now,
            ]);
        }
        $campaign = self::getCampaign($campaignId);
        NotificationEvent::raiseEvent(self::REQUEST_EVENT, $policy, [
            'entities_id' => 0,
            'users'       => array_keys($members),
            'due_date'    => (string) ($campaign['due_date'] ?? ''),
        ]);

        return count($members);
    }

    /**
     * Nouvelle version d'une politique déjà diffusée : nouvelle campagne (Policy::post_updateItem()).
     *
     * @return int nombre de personnes du public, 0 si aucune campagne n'a été relancée
     */
    public static function relaunchIfNewVersion(Policy $policy): int
    {
        $current = self::getCurrentCampaign($policy->getID());
        if (
            !AcknowledgementRules::shouldRelaunch(
                (string) ($policy->fields['status'] ?? ''),
                self::hasAudience($policy->getID()),
                $current === null ? null : (string) $current['version'],
                (string) ($policy->fields['version'] ?? '')
            )
        ) {
            return 0;
        }

        return max(0, self::launch($policy, (int) Session::getLoginUserID()));
    }

    /**
     * Lignes d'une campagne, avec le nom de la personne et de son entité. Pour un gestionnaire,
     * seulement les entités de sa session ($restrict).
     *
     * @return list<array{id: int, users_id: int, entities_id: int, user_name: string, entity: string,
     *     date_acknowledged: ?string, date_reminder: ?string, late: bool}>
     */
    public static function getLines(int $campaignId, bool $restrict = true): array
    {
        global $DB;

        $campaign = self::getCampaign($campaignId);
        if ($campaign === null) {
            return [];
        }
        $where = ['plugin_grcmanager_policycampaigns_id' => $campaignId];
        if ($restrict) {
            $where[] = getEntitiesRestrictCriteria(self::LINES_TABLE, 'entities_id', '', false);
        }
        $today = date('Y-m-d');
        $lines = [];
        foreach ($DB->request(['FROM' => self::LINES_TABLE, 'WHERE' => $where, 'ORDER' => ['id']]) as $row) {
            $acknowledged = $row['date_acknowledged'] ?: null;
            $lines[] = [
                'id'                => (int) $row['id'],
                'users_id'          => (int) $row['users_id'],
                'entities_id'       => (int) $row['entities_id'],
                'user_name'         => (string) getUserName((int) $row['users_id']),
                'entity'            => (string) Dropdown::getDropdownName(Entity::getTable(), (int) $row['entities_id']),
                'date_acknowledged' => $acknowledged,
                'date_reminder'     => $row['date_reminder'] ?: null,
                'late'              => AcknowledgementRules::isLate($acknowledged, (string) $campaign['due_date'], $today),
            ];
        }
        usort($lines, static fn (array $a, array $b): int => [$a['entity'], $a['user_name']] <=> [$b['entity'], $b['user_name']]);

        return $lines;
    }

    /**
     * Synthèse d'une campagne (lignes visibles) : avancement, par entité, retardataires.
     *
     * @return array<string, mixed>
     */
    public static function summarize(array $campaign, bool $restrict = true): array
    {
        $lines = self::getLines((int) $campaign['id'], $restrict);
        $today = date('Y-m-d');
        $due = (string) $campaign['due_date'];
        $byEntity = [];
        foreach (AcknowledgementRules::progressByEntity($lines, $due, $today) as $entityId => $progress) {
            $byEntity[] = $progress + [
                'entity' => (string) Dropdown::getDropdownName(Entity::getTable(), $entityId),
            ];
        }
        usort($byEntity, static fn (array $a, array $b): int => strcmp($a['entity'], $b['entity']));

        return [
            'campaign'   => $campaign,
            'lines'      => $lines,
            'progress'   => AcknowledgementRules::progress($lines, $due, $today),
            'by_entity'  => $byEntity,
            'latecomers' => AcknowledgementRules::latecomers($lines, $due, $today),
            'is_current' => self::isCurrent($campaign),
            'is_open'    => ($campaign['status'] ?? '') === AcknowledgementRules::STATUS_OPEN,
            'late'       => $today > $due,
            'dates'      => [
                'launch' => Html::convDateTime($campaign['date_launch']),
                'due'    => Html::convDate($campaign['due_date']),
                'close'  => Html::convDateTime($campaign['date_close']),
            ],
            'launched_by' => (string) getUserName((int) $campaign['users_id']),
        ];
    }

    /**
     * Confirmation de prise de connaissance par la personne elle-même, sur la campagne en vigueur.
     *
     * @return string message d'erreur, chaîne vide si la confirmation est enregistrée
     */
    public static function acknowledge(int $lineId, int $usersId): string
    {
        global $DB;

        $line = $DB->request(['FROM' => self::LINES_TABLE, 'WHERE' => ['id' => $lineId]])->current();
        if ($line === null || (int) $line['users_id'] !== $usersId || $usersId <= 0) {
            return __('This policy is not waiting for your acknowledgement.', 'grcmanager');
        }
        $campaign = self::getCampaign((int) $line['plugin_grcmanager_policycampaigns_id']);
        if (
            $campaign === null
            || $campaign['status'] !== AcknowledgementRules::STATUS_OPEN
            || !self::isCurrent($campaign)
        ) {
            return __('This acknowledgement campaign is closed or replaced by a newer version.', 'grcmanager');
        }
        if (!empty($line['date_acknowledged'])) {
            return '';
        }
        $DB->update(self::LINES_TABLE, ['date_acknowledged' => date('Y-m-d H:i:s')], ['id' => $lineId]);

        return '';
    }

    /**
     * Personnes qui n'ont pas encore confirmé, sur la campagne en vigueur et ouverte.
     *
     * @return list<int>
     */
    public static function getPendingUserIds(int $policyId): array
    {
        global $DB;

        $campaign = self::getCurrentCampaign($policyId);
        if ($campaign === null || $campaign['status'] !== AcknowledgementRules::STATUS_OPEN) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => self::LINES_TABLE,
                'WHERE'  => ['plugin_grcmanager_policycampaigns_id' => (int) $campaign['id'], 'date_acknowledged' => null],
            ]) as $row
        ) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    /**
     * Relances : notification aux personnes qui n'ont pas confirmé. Par la tâche planifiée, seules
     * celles en retard et pas relancées depuis une semaine ; à la demande ($campaignId), toutes
     * celles qui n'ont pas confirmé et des entités de la session.
     *
     * @return int nombre de personnes relancées
     */
    public static function sendReminders(string $today, int $campaignId = 0): int
    {
        global $DB;

        $where = ['status' => AcknowledgementRules::STATUS_OPEN];
        if ($campaignId > 0) {
            $where['id'] = $campaignId;
        }
        $count = 0;
        foreach ($DB->request(['FROM' => self::CAMPAIGNS_TABLE, 'WHERE' => $where]) as $campaign) {
            if (!self::isCurrent($campaign)) {
                continue;
            }
            $lineWhere = [
                'plugin_grcmanager_policycampaigns_id' => (int) $campaign['id'],
                'date_acknowledged'                    => null,
            ];
            if ($campaignId > 0) {
                $lineWhere[] = getEntitiesRestrictCriteria(self::LINES_TABLE, 'entities_id', '', false);
            }
            $users = [];
            $lineIds = [];
            foreach ($DB->request(['FROM' => self::LINES_TABLE, 'WHERE' => $lineWhere]) as $line) {
                $due = $campaignId > 0 || AcknowledgementRules::isReminderDue(
                    null,
                    (string) $campaign['due_date'],
                    $line['date_reminder'] ?: null,
                    $today
                );
                if ($due) {
                    $users[] = (int) $line['users_id'];
                    $lineIds[] = (int) $line['id'];
                }
            }
            if ($users === []) {
                continue;
            }
            $policy = new Policy();
            if (!$policy->getFromDB((int) $campaign['plugin_grcmanager_policies_id'])) {
                continue;
            }
            NotificationEvent::raiseEvent(self::REMINDER_EVENT, $policy, [
                'entities_id' => 0,
                'users'       => $users,
                'due_date'    => (string) $campaign['due_date'],
            ]);
            $DB->update(self::LINES_TABLE, ['date_reminder' => date('Y-m-d H:i:s')], ['id' => $lineIds]);
            $count += count($users);
        }

        return $count;
    }

    /**
     * Clôture : plus de confirmation ni de relance ; le rapport PDF (taux par entité,
     * retardataires) est archivé comme preuve sur la mesure A.5.1 et sur la politique.
     *
     * @return int id du document archivé, 0 s'il n'a pas pu l'être, -1 si la campagne est déjà close
     */
    public static function close(int $campaignId): int
    {
        global $DB;

        $campaign = self::getCampaign($campaignId);
        if ($campaign === null || $campaign['status'] !== AcknowledgementRules::STATUS_OPEN) {
            return -1;
        }
        $now = date('Y-m-d H:i:s');
        $DB->update(self::CAMPAIGNS_TABLE, [
            'status'     => AcknowledgementRules::STATUS_CLOSED,
            'date_close' => $now,
            'date_mod'   => $now,
        ], ['id' => $campaignId]);
        $campaign = self::getCampaign($campaignId);
        $documentsId = self::archiveReport($campaign);
        $DB->update(self::CAMPAIGNS_TABLE, ['documents_id' => $documentsId], ['id' => $campaignId]);

        return $documentsId;
    }

    public static function renderReport(array $campaign): string
    {
        $policy = new Policy();
        $policy->getFromDB((int) $campaign['plugin_grcmanager_policies_id']);

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/policy_acknowledgement_report.html.twig', [
            'report_title'       => sprintf(
                __('Policy acknowledgement: %1$s, version %2$s', 'grcmanager'),
                (string) ($policy->fields['title'] ?? ''),
                (string) $campaign['version']
            ),
            'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime(date('Y-m-d H:i:s'))),
            'control'            => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
        ] + self::summarize($campaign));
    }

    /**
     * @param array<string, mixed> $campaign
     */
    private static function archiveReport(array $campaign): int
    {
        global $DB;

        $fileName = sprintf('prise-de-connaissance-%d-%s.pdf', (int) $campaign['id'], date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, PdfRenderer::renderHtmlToPdf(self::renderReport($campaign))) === false) {
            return 0;
        }
        $policyId = (int) $campaign['plugin_grcmanager_policies_id'];
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                __('Policy acknowledgement report - %1$s %2$s', 'grcmanager'),
                Dropdown::getDropdownName(Policy::getTable(), $policyId),
                (string) $campaign['version']
            ),
            // Les lignes du rapport sont celles des entités de la session de qui clôture.
            'entities_id'   => Session::getActiveEntity(),
            'is_recursive'  => Session::getIsActiveEntityRecursive() ? 1 : 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        $targets = [[Policy::class, $policyId]];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Control::getTable(),
                'WHERE'  => ['code' => self::CONTROL_CODE],
            ]) as $control
        ) {
            $targets[] = [Control::class, (int) $control['id']];
        }
        foreach ($targets as [$itemtype, $itemsId]) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
            ]);
        }
        // Noms des retardataires : lisible par qui lit les politiques, pas par le seul droit Documents.
        RestrictedDocument::registerFor($documentsId, self::class, (int) $campaign['id']);

        return $documentsId;
    }

    /** Source d'un rapport archivé (RestrictedDocument) : lisible par qui lit les politiques. */
    public static function canReadReport(int $campaignId): bool
    {
        return Policy::canView() && self::getCampaign($campaignId) !== null;
    }

    // ---------------------------------------------------------------------------------------------
    // Espace de la personne
    // ---------------------------------------------------------------------------------------------

    /**
     * Politiques que la personne doit lire (campagne en vigueur et ouverte, pas encore confirmée)
     * et celles dont elle a déjà pris connaissance.
     *
     * @return list<array<string, mixed>>
     */
    public static function getMine(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }
        $list = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'l.id AS line_id', 'l.date_acknowledged', 'c.id AS campaign_id', 'c.version', 'c.status',
                    'c.due_date', 'c.plugin_grcmanager_policies_id', 'p.title', 'p.description',
                ],
                'FROM'       => self::LINES_TABLE . ' AS l',
                'INNER JOIN' => [
                    self::CAMPAIGNS_TABLE . ' AS c' => [
                        'FKEY' => ['l' => 'plugin_grcmanager_policycampaigns_id', 'c' => 'id'],
                    ],
                    Policy::getTable() . ' AS p' => [
                        'FKEY' => ['c' => 'plugin_grcmanager_policies_id', 'p' => 'id'],
                    ],
                ],
                'WHERE'      => ['l.users_id' => $usersId],
                'ORDER'      => ['c.id DESC'],
            ]) as $row
        ) {
            $policyId = (int) $row['plugin_grcmanager_policies_id'];
            $current = self::getCurrentCampaign($policyId);
            $isCurrent = $current !== null && (int) $current['id'] === (int) $row['campaign_id'];
            $acknowledged = $row['date_acknowledged'] ?: null;
            // Une campagne remplacée n'attend plus rien : seule sa confirmation reste en historique.
            if (!$isCurrent && $acknowledged === null) {
                continue;
            }
            $pending = $acknowledged === null && $row['status'] === AcknowledgementRules::STATUS_OPEN;
            if ($acknowledged === null && !$pending) {
                continue;
            }
            $list[] = [
                'line_id'      => (int) $row['line_id'],
                'policies_id'  => $policyId,
                'title'        => (string) $row['title'],
                'description'  => (string) $row['description'],
                'version'      => (string) $row['version'],
                'due_date'     => (string) $row['due_date'],
                'pending'      => $pending,
                'late'         => $pending && date('Y-m-d') > (string) $row['due_date'],
                'acknowledged' => $acknowledged,
            ];
        }

        return $list;
    }

    /**
     * Documents joints à la politique, téléchargeables par les membres de son public.
     *
     * @return list<array{id: int, name: string, url: string}>
     */
    public static function getPolicyDocuments(int $policyId): array
    {
        global $DB;

        $list = [];
        foreach (
            $DB->request([
                'SELECT'     => ['d.id', 'd.name', 'd.filename'],
                'FROM'       => Document_Item::getTable() . ' AS di',
                'INNER JOIN' => [Document::getTable() . ' AS d' => ['FKEY' => ['di' => 'documents_id', 'd' => 'id']]],
                'LEFT JOIN'  => [
                    RestrictedDocument::TABLE . ' AS r' => ['FKEY' => ['r' => 'documents_id', 'd' => 'id']],
                ],
                'WHERE'      => [
                    'di.itemtype' => Policy::class,
                    'di.items_id' => $policyId,
                    'd.is_deleted' => 0,
                    // Rapports archivés (noms des retardataires) : jamais remis au public.
                    'r.id'        => null,
                ],
                'ORDER'      => ['d.name'],
            ]) as $row
        ) {
            $list[] = [
                'id'   => (int) $row['id'],
                'name' => (string) ($row['name'] ?: $row['filename']),
                'url'  => self::getDownloadURL($policyId, (int) $row['id']),
            ];
        }

        return $list;
    }

    /** La personne fait-elle partie du public d'une campagne de cette politique ? */
    public static function isInAudience(int $policyId, int $usersId): bool
    {
        global $DB;

        return $usersId > 0 && $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => self::LINES_TABLE . ' AS l',
            'INNER JOIN' => [
                self::CAMPAIGNS_TABLE . ' AS c' => [
                    'FKEY' => ['l' => 'plugin_grcmanager_policycampaigns_id', 'c' => 'id'],
                ],
            ],
            'WHERE'      => ['l.users_id' => $usersId, 'c.plugin_grcmanager_policies_id' => $policyId],
        ])->current()['cpt'] > 0;
    }

    public static function canDownload(int $policyId, int $documentsId, int $usersId): bool
    {
        if (!self::isInAudience($policyId, $usersId)) {
            return false;
        }

        return in_array($documentsId, array_column(self::getPolicyDocuments($policyId), 'id'), true);
    }

    /** Nombre de politiques que la personne doit encore lire. */
    public static function countPending(int $usersId): int
    {
        return count(array_filter(self::getMine($usersId), static fn (array $row): bool => $row['pending']));
    }

    // ---------------------------------------------------------------------------------------------
    // Onglets
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(
                __('Policies to read', 'grcmanager'),
                self::countPending((int) Session::getLoginUserID()),
                null,
                'ti ti-file-check'
            );
        }
        if (!$item instanceof Policy || $item->isNewItem() || !Policy::canView()) {
            return '';
        }

        return self::createTabEntry(__('Acknowledgement', 'grcmanager'), 0, null, 'ti ti-file-check');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if ($item instanceof Preference) {
            self::showMine();

            return true;
        }
        if (!$item instanceof Policy || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForPolicy($item);

        return true;
    }

    private static function showForPolicy(Policy $policy): void
    {
        global $CFG_GLPI;

        $policyId = $policy->getID();
        $current = self::getCurrentCampaign($policyId);
        $hasAudience = self::hasAudience($policyId);
        $version = (string) ($policy->fields['version'] ?? '');
        $status = (string) ($policy->fields['status'] ?? '');
        $history = [];
        foreach (self::getCampaigns($policyId) as $index => $campaign) {
            if ($index === 0) {
                continue;
            }
            $summary = self::summarize($campaign);
            $history[] = [
                'version'    => (string) $campaign['version'],
                'status'     => (string) $campaign['status'],
                'launch'     => $summary['dates']['launch'],
                'close'      => $summary['dates']['close'],
                'progress'   => $summary['progress'],
                'id'         => (int) $campaign['id'],
                'report_url' => (int) $campaign['documents_id'] > 0
                    ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $campaign['documents_id']
                    : '',
                'csv_url'    => self::getExportURL((int) $campaign['id'], 'csv'),
            ];
        }
        $canUpdate = $policy->can($policyId, UPDATE);

        TemplateRenderer::getInstance()->display('@grcmanager/policy_acknowledgement_tab.html.twig', [
            'policy_id'       => $policyId,
            'version'         => $version,
            'is_approved'     => $status === PolicyLifecycle::STATUS_APPROVED,
            'audience'        => self::getAudience($policyId),
            'members_count'   => $hasAudience ? count(self::resolveMembers($policyId)) : 0,
            'days'            => AcknowledgementRules::sanitizeDays($policy->fields['acknowledgement_days'] ?? null),
            'min_days'        => AcknowledgementRules::MIN_DAYS,
            'max_days'        => AcknowledgementRules::MAX_DAYS,
            'can_update'      => $canUpdate,
            'can_launch'      => $canUpdate && AcknowledgementRules::canLaunch(
                $status,
                $hasAudience,
                $current === null ? null : (string) $current['version'],
                $version
            ),
            'needs_new'       => $current !== null && (string) $current['version'] !== $version,
            'current'         => $current === null ? null : self::summarize($current),
            'history'         => $history,
            'csv_url'         => $current === null ? '' : self::getExportURL((int) $current['id'], 'csv'),
            'pdf_url'         => $current === null ? '' : self::getExportURL((int) $current['id'], 'pdf'),
            'report_url'      => $current !== null && (int) $current['documents_id'] > 0
                ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $current['documents_id']
                : '',
            'action'          => self::getFormURL(),
            'control_code'    => self::CONTROL_CODE,
            'good_rate'       => AcknowledgementRules::GOOD_RATE,
            'group_dropdown'  => Group::dropdown([
                'name' => 'items_id', 'display' => false, 'entity' => $_SESSION['glpiactiveentities'] ?? 0,
                'entity_sons' => true, 'rand' => 'grcackgroup',
            ]),
            'profile_dropdown' => Profile::dropdown(['name' => 'items_id', 'display' => false, 'rand' => 'grcackprofile']),
            'entity_dropdown' => Entity::dropdown([
                'name' => 'items_id', 'display' => false, 'entity' => $_SESSION['glpiactiveentities'] ?? 0,
                'rand' => 'grcackentity',
            ]),
        ]);
    }

    private static function showMine(): void
    {
        $rows = [];
        foreach (self::getMine((int) Session::getLoginUserID()) as $row) {
            $rows[] = $row + [
                'due_text'          => Html::convDate($row['due_date']),
                'acknowledged_text' => $row['acknowledged'] !== null ? Html::convDateTime($row['acknowledged']) : '',
                'documents'         => self::getPolicyDocuments($row['policies_id']),
            ];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/my_policies_tab.html.twig', [
            'pending' => array_values(array_filter($rows, static fn (array $r): bool => $r['pending'])),
            'done'    => array_values(array_filter($rows, static fn (array $r): bool => !$r['pending'])),
            'action'  => self::getFormURL(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Tâche planifiée et nettoyage
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function cronInfo(string $name): array
    {
        return ['description' => __('Reminds people who have not acknowledged a security policy by its deadline', 'grcmanager')];
    }

    public static function cronPolicyacknowledgementreminder(CronTask $task): int
    {
        $count = self::sendReminders(date('Y-m-d'));
        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    /** Politique purgée : public, campagnes et confirmations disparaissent avec elle. */
    public static function purgePolicy(int $policyId): void
    {
        global $DB;

        $campaignIds = array_map(static fn (array $c): int => (int) $c['id'], self::getCampaigns($policyId));
        if ($campaignIds !== []) {
            $DB->delete(self::LINES_TABLE, ['plugin_grcmanager_policycampaigns_id' => $campaignIds]);
            $DB->delete(self::CAMPAIGNS_TABLE, ['id' => $campaignIds]);
        }
        $DB->delete(self::AUDIENCE_TABLE, ['plugin_grcmanager_policies_id' => $policyId]);
    }
}
