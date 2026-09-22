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

use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyReviewReminderService;

/**
 * Security policy library (issue #28, ISO/IEC 27001:2022 A.5.1/A.5.1.1/A.5.1.2): the control
 * A.5.1 ("Politiques de sécurité de l'information") existed until now only as a checkbox row in
 * the SoA (PluginGrcmanagerControl), with no actual operational tool behind it. This class gives
 * the RSSI a real place to manage security policy documents (charte informatique, politique de
 * mots de passe, PCA...) with a real lifecycle: draft/approved/archived status, a free-text
 * version string (e.g. "1.2"), an approval date, and a next review date driving the same kind of
 * automatic reminder already in place for the risk register (see PolicyReviewReminderService,
 * mirroring GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService).
 *
 * The actual policy document (PDF, Word...) is never stored by this plugin itself: attached via
 * GLPI's own native Document/Document_Item polymorphic relation (see defineTabs() below), the same
 * mechanism every core GLPI itemtype uses for its own "Documents" tab, confirmed by reading GLPI
 * 11 core (src/Computer.php's own defineTabs(), src/Document_Item.php, src/Document.php). Adding
 * a new file-storage mechanism of this plugin's own was explicitly out of scope for this issue.
 */
class PluginGrcmanagerPolicy extends CommonDBTM
{
    public static $rightname = 'plugin_grcmanager';

    /**
     * GLPI notification event name (see inc/notificationtargetpolicy.class.php and
     * src/Services/Policy/PolicyReviewReminderService.php), shared here as a single source of
     * truth so the event string never drifts between the NotificationTarget, the reminder service,
     * and the Cron entry point below.
     */
    public const REVIEW_DUE_EVENT = PolicyReviewReminderService::EVENT;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_policies';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Politique de sécurité', 'Politiques de sécurité', $nb, 'grcmanager');
    }

    /**
     * This class' primary display column is `title`, not GLPI's default `name` (which this table
     * doesn't even have). Front-page-bar bug found live: `front/policy.form.php` is this plugin's
     * only front controller that calls `displayFullPageForItem()` (needed so the native
     * "Documents" tab actually renders, see that file's own comment) — that path builds the page
     * header/breadcrumb from `getNameField()`, so without this override it silently read a
     * nonexistent `name` field and printed the literal string "N/A" instead of the policy's real
     * title. Every sibling class here (Risk, Audit...) uses a plain `Html::header()` call instead,
     * which never looks up a name field at all, so none of them needed this override.
     */
    public static function getNameField()
    {
        return 'title';
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            PolicyLifecycle::STATUS_DRAFT    => __('Brouillon', 'grcmanager'),
            PolicyLifecycle::STATUS_APPROVED => __('Approuvée', 'grcmanager'),
            PolicyLifecycle::STATUS_ARCHIVED => __('Archivée', 'grcmanager'),
        ];
    }

    /**
     * Grants this class GLPI's own native "Documents" tab (Document_Item), so a policy's actual
     * file(s) (PDF, Word...) can be attached without this plugin inventing any storage mechanism
     * of its own - confirmed against GLPI 11 core that a plain CommonDBTM gets NO tab for free:
     * every core itemtype that has a "Documents" tab (Computer, Ticket, KnowbaseItem...) adds it
     * explicitly in its own defineTabs() override, exactly as done here.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options);

        return $tabs;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'       => 1,
            'table'    => $this->getTable(),
            'field'    => 'title',
            'name'     => __('Titre', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'version',
            'name'     => __('Version', 'grcmanager'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Statut', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'approval_date',
            'name'     => __('Date d\'approbation', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'next_review_date',
            'name'     => __('Prochaine revue', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Propriétaire', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family, see PluginGrcmanagerRisk::rawSearchOptions().
        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        return $tab;
    }

    /**
     * Translates the raw `status` DB value into a color-coded Tabler badge, same UX convention as
     * every other enum column in this plugin (see PluginGrcmanagerRisk::getSpecificValueToDisplay()).
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            return self::statusBadge($values[$field] ?? null);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widget for `status`, same lesson (and same reason) as
     * PluginGrcmanagerRisk::getSpecificValueToSelect(): 'specific' falls through to a free-text
     * box by default, not self-explanatory for a non-technical user.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        if ($field === 'status') {
            return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            PolicyLifecycle::STATUS_DRAFT    => ['bg-secondary-lt', 'ti-pencil', __('Brouillon', 'grcmanager')],
            PolicyLifecycle::STATUS_APPROVED => ['bg-green-lt', 'ti-circle-check', __('Approuvée', 'grcmanager')],
            PolicyLifecycle::STATUS_ARCHIVED => ['bg-yellow-lt', 'ti-archive', __('Archivée', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    /**
     * Enforces ISO 27001 A.5.1.1: a policy cannot be marked "approved" without a recorded approval
     * date (see PolicyLifecycle::isApprovalDateMissing()). Returning false here aborts add()/
     * update() the same way GLPI core itself treats a false return from prepareInputForAdd()/
     * prepareInputForUpdate(), with a real, visible error message, same convention already used by
     * PluginGrcmanagerControl::prepareInputForAdd()/prepareInputForUpdate().
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateStatus($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateStatus(array $input)
    {
        $status = PolicyLifecycle::sanitize(
            (string) ($input['status'] ?? ($this->fields['status'] ?? PolicyLifecycle::STATUS_DRAFT))
        );
        $approvalDate = $input['approval_date'] ?? ($this->fields['approval_date'] ?? null);

        if (PolicyLifecycle::isApprovalDateMissing($status, $approvalDate)) {
            Session::addMessageAfterRedirect(
                __(
                    'Une date d\'approbation est obligatoire pour marquer une politique comme approuvée.',
                    'grcmanager'
                ),
                false,
                ERROR
            );

            return false;
        }

        $input['status'] = $status;

        return $input;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo '<tr class="tab_bg_1"><td>' . __('Titre', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        echo Html::input('title', ['value' => $this->fields['title'] ?? '', 'size' => 80]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Version', 'grcmanager') . '</td><td>';
        echo Html::input('version', ['value' => $this->fields['version'] ?? '1.0', 'size' => 10]);
        echo '</td>';

        echo '<td>' . __('Propriétaire', 'grcmanager') . '</td><td>';
        User::dropdown([
            'name'  => 'users_id',
            'value' => $this->fields['users_id'] ?? 0,
            'right' => 'all',
        ]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Statut', 'grcmanager') . '</td><td>';
        Dropdown::showFromArray('status', self::getStatuses(), [
            'value' => $this->fields['status'] ?? PolicyLifecycle::STATUS_DRAFT,
        ]);
        echo '<small class="form-hint">' . __(
            'Une date d\'approbation est obligatoire pour passer au statut "Approuvée".',
            'grcmanager'
        ) . '</small>';
        echo '</td><td colspan="2"></td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Date d\'approbation', 'grcmanager') . '</td><td>';
        Html::showDateField('approval_date', ['value' => $this->fields['approval_date'] ?? '']);
        echo '</td>';

        echo '<td>' . __('Prochaine revue', 'grcmanager') . '</td><td>';
        Html::showDateField('next_review_date', ['value' => $this->fields['next_review_date'] ?? '']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Description', 'grcmanager') . '</td>';
        echo '<td colspan="3">';
        echo '<textarea name="description" class="form-control" rows="4">'
            . htmlescape($this->fields['description'] ?? '') . '</textarea>';
        echo '</td></tr>';

        $this->showFormButtons($options);

        return true;
    }

    /**
     * GLPI Cron entry point, registered via CronTask::Register() in the plugin installer
     * (src/Install/Installer.php), same structure as PluginGrcmanagerRisk::cronReviewreminder().
     * Finds policies whose next review date has passed or is within the reminder window and
     * raises a real GLPI Notification for each (see PolicyReviewReminderService,
     * inc/notificationtargetpolicy.class.php).
     *
     * @return int 0 if no policy was due, 1 otherwise
     */
    public static function cronReviewreminder(CronTask $task): int
    {
        $result = (new PolicyReviewReminderService())->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            '%d politique(s) en attente de revue, %d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}
