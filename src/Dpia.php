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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\DpiaBase;
use GlpiPlugin\Grcmanager\Services\Privacy\DpiaRules;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use Html;
use Log;
use Session;

/**
 * Issue #375 : analyse d'impact relative à la protection des données (AIPD, RGPD article 35), une par
 * fiche du registre des traitements, dont elle reprend les droits et l'entité. Une AIPD approuvée est
 * figée : la rouvrir retire l'approbation. Le statut de la fiche et le registre des risques ne changent
 * que sur une action de l'utilisateur.
 */
class Dpia extends DpiaBase
{
    public const RIGHTNAME = ProcessingActivity::RIGHTNAME;

    public const ACTIVITY_FK = 'plugin_grcmanager_processingactivities_id';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_dpias';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('DPIA', 'DPIAs', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-scale';
    }

    /**
     * @return array<string, string>
     */
    public static function getCriteriaLabels(): array
    {
        return [
            'evaluation_scoring'    => __('Evaluation or scoring of people', 'grcmanager'),
            'automated_decision'    => __('Automated decisions with legal or similar effects', 'grcmanager'),
            'systematic_monitoring' => __('Systematic monitoring of people', 'grcmanager'),
            'sensitive_data'        => __('Sensitive or highly personal data', 'grcmanager'),
            'large_scale'           => __('Processing on a large scale', 'grcmanager'),
            'matching_datasets'     => __('Matching or combining datasets', 'grcmanager'),
            'vulnerable_subjects'   => __('Data about vulnerable people', 'grcmanager'),
            'innovative_use'        => __('Innovative use of technology', 'grcmanager'),
            'blocks_rights'         => __('Prevents the exercise of a right or access to a service', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getRiskLabels(): array
    {
        return [
            'illegitimate_access' => __('Illegitimate access to data', 'grcmanager'),
            'unwanted_change'     => __('Unwanted change of data', 'grcmanager'),
            'disappearance'       => __('Disappearance of data', 'grcmanager'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getScaleLabels(): array
    {
        return [
            1 => __('1 - Negligible', 'grcmanager'),
            2 => __('2 - Limited', 'grcmanager'),
            3 => __('3 - Significant', 'grcmanager'),
            4 => __('4 - Maximum', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getLevelLabels(): array
    {
        return [
            'none'   => __('Not rated', 'grcmanager'),
            'low'    => __('Low risk', 'grcmanager'),
            'medium' => __('Moderate risk', 'grcmanager'),
            'high'   => __('High risk', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getMissingLabels(): array
    {
        return [
            'necessity'   => __('Necessity and proportionality', 'grcmanager'),
            'risks'       => __('Rating of the three risks, before and after measures', 'grcmanager'),
            'measures'    => __('Planned measures', 'grcmanager'),
            'dpo_opinion' => __('Opinion of the DPO', 'grcmanager'),
        ];
    }

    public static function forActivity(int $activityId): ?self
    {
        $dpia = new self();

        return $dpia->getFromDBByCrit([self::ACTIVITY_FK => $activityId]) ? $dpia : null;
    }

    private static function activityCan(int $activityId, int $right): bool
    {
        return $activityId > 0 && (new ProcessingActivity())->can($activityId, $right);
    }

    public function canViewItem(): bool
    {
        return self::activityCan((int) ($this->fields[self::ACTIVITY_FK] ?? 0), READ);
    }

    public function canCreateItem(): bool
    {
        return self::activityCan((int) ($this->input[self::ACTIVITY_FK] ?? 0), UPDATE);
    }

    public function canUpdateItem(): bool
    {
        return self::activityCan((int) ($this->fields[self::ACTIVITY_FK] ?? 0), UPDATE);
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof ProcessingActivity) || $item->isNewItem() || !self::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(1),
            self::forActivity((int) $item->getID()) !== null ? 1 : 0,
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof ProcessingActivity) || !self::canView()) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/dpia.html.twig', self::viewData($item));

        return true;
    }

    /**
     * Enregistre le formulaire ; refusé sur une AIPD approuvée, qu'il faut d'abord rouvrir.
     *
     * @param array<string, mixed> $post
     */
    public static function saveForm(int $activityId, array $post): bool
    {
        $dpia = self::forActivity($activityId);
        if ($dpia !== null && $dpia->fields['status'] === DpiaRules::STATUS_APPROVED) {
            Session::addMessageAfterRedirect(
                __('This DPIA is approved: reopen it to change it.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }
        $input = [
            'criteria'            => json_encode(DpiaRules::normalizeCriteria($post['criteria'] ?? [])),
            'risk_assessment'     => json_encode(DpiaRules::normalizeRisks($post['risks'] ?? [])),
            'description'         => (string) ($post['description'] ?? ''),
            'necessity'           => (string) ($post['necessity'] ?? ''),
            'measures'            => (string) ($post['measures'] ?? ''),
            'data_subjects_views' => (string) ($post['data_subjects_views'] ?? ''),
            'dpo_opinion'         => (string) ($post['dpo_opinion'] ?? ''),
            'users_id_dpo'        => (int) ($post['users_id_dpo'] ?? 0),
        ];
        $before = (string) ($dpia->fields['dpo_opinion'] ?? '');
        if (trim($input['dpo_opinion']) !== trim($before)) {
            $input['date_dpo_opinion'] = trim($input['dpo_opinion']) === ''
                ? null
                : (Session::getCurrentTime() ?? date('Y-m-d H:i:s'));
        }
        if ($dpia === null) {
            return (bool) (new self())->add($input + [self::ACTIVITY_FK => $activityId]);
        }

        return $dpia->update($input + ['id' => (int) $dpia->getID()]);
    }

    /**
     * Approbation par le responsable de traitement : signée, et refusée s'il manque un élément.
     */
    public function approve(): bool
    {
        $missing = DpiaRules::missingForApproval($this->fields);
        if ($missing !== []) {
            $labels = array_map(static fn (string $key): string => self::getMissingLabels()[$key] ?? $key, $missing);
            Session::addMessageAfterRedirect(
                sprintf(__('This DPIA cannot be approved yet, missing: %s', 'grcmanager'), implode(', ', $labels)),
                false,
                ERROR
            );

            return false;
        }

        return $this->changeState([
            'status'            => DpiaRules::STATUS_APPROVED,
            'users_id_approval' => (int) Session::getLoginUserID(),
            'date_approval'     => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
        ], __('DPIA approved', 'grcmanager'));
    }

    public function reopen(): bool
    {
        return $this->changeState([
            'status'            => DpiaRules::STATUS_DRAFT,
            'users_id_approval' => 0,
            'date_approval'     => null,
        ], __('DPIA reopened, approval withdrawn', 'grcmanager'));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function changeState(array $state, string $message): bool
    {
        if (!$this->update($state + ['id' => (int) $this->getID()])) {
            return false;
        }
        $activityId = (int) $this->fields[self::ACTIVITY_FK];
        Log::history($activityId, ProcessingActivity::class, [0, '', $message], 0, Log::HISTORY_LOG_SIMPLE_MESSAGE);
        AuditLog::record('update', self::class, (int) $this->getID());
        Session::addMessageAfterRedirect($message);

        return true;
    }

    /**
     * Applique à la fiche du registre l'état proposé, sur demande de l'utilisateur.
     */
    public static function applyRecordStatus(ProcessingActivity $activity): bool
    {
        $dpia = self::forActivity((int) $activity->getID());
        $suggested = DpiaRules::suggestedRecordStatus(
            (string) $activity->fields['dpia_status'],
            $dpia->fields['criteria'] ?? '',
            (string) ($dpia->fields['status'] ?? DpiaRules::STATUS_DRAFT)
        );
        if ($dpia === null || $suggested === null) {
            return false;
        }

        return $activity->update(['id' => (int) $activity->getID(), 'dpia_status' => $suggested]);
    }

    /**
     * Crée le risque résiduel le plus fort dans le registre, une seule fois. Renvoie 0 sans droit ou
     * si aucun risque résiduel n'est coté.
     */
    public function sendToRegister(ProcessingActivity $activity): int
    {
        global $DB;

        if (!Risk::canCreate()) {
            return 0;
        }
        $riskKey = Risk::getForeignKeyField();
        $linked = (int) $this->fields[$riskKey];
        if ($linked > 0 && (new Risk())->getFromDB($linked)) {
            return $linked;
        }
        $worst = DpiaRules::worstResidualRisk($this->fields['risk_assessment']);
        if ($worst === null) {
            return 0;
        }
        $riskId = (new Risk())->add([
            'title'       => sprintf(__('%1$s: %2$s'), self::getTypeName(1), (string) $activity->fields['name']),
            'description' => sprintf(
                __('%1$s: %2$s'),
                self::getRiskLabels()[$worst['risk']],
                (string) $this->fields['measures']
            ),
            'category'    => 'process',
            'probability' => $worst['probability'],
            'impact'      => $worst['impact'],
            'users_id'    => (int) ($activity->fields['users_id'] ?? 0),
        ]);
        if ($riskId === false || (int) $riskId <= 0) {
            return 0;
        }
        $DB->update(self::getTable(), [$riskKey => (int) $riskId], ['id' => (int) $this->getID()]);
        $this->fields[$riskKey] = (int) $riskId;
        Session::addMessageAfterRedirect(__('The risk has been added to the register.', 'grcmanager'));

        return (int) $riskId;
    }

    /**
     * Données communes à l'onglet et au rapport.
     *
     * @return array<string, mixed>
     */
    public static function viewData(ProcessingActivity $activity): array
    {
        $activityId = (int) $activity->getID();
        $dpia = self::forActivity($activityId);
        $fields = $dpia->fields ?? [];
        // Sans AIPD enregistrée, le critère des données sensibles reprend la fiche du registre.
        $criteria = $dpia !== null
            ? DpiaRules::normalizeCriteria($fields['criteria'])
            : (ProcessingActivity::listLabels('sensitive_data', $activity->fields['sensitive_data'] ?? '') !== []
                ? ['sensitive_data'] : []);
        $status = (string) ($fields['status'] ?? DpiaRules::STATUS_DRAFT);
        $risks = DpiaRules::normalizeRisks($fields['risk_assessment'] ?? '');
        $rows = [];
        foreach (self::getRiskLabels() as $key => $label) {
            $risk = $risks[$key];
            $rows[] = $risk + [
                'key'            => $key,
                'label'          => $label,
                'level'          => DpiaRules::level($risk['severity'], $risk['likelihood']),
                'residual_level' => DpiaRules::level($risk['residual_severity'], $risk['residual_likelihood']),
            ];
        }
        $recordStatus = (string) ($activity->fields['dpia_status'] ?? 'not_assessed');
        $canEdit = ProcessingActivity::canUpdate() && $activity->canUpdateItem();
        $worst = DpiaRules::worstResidualRisk($risks);

        return [
            'activity'             => $activity,
            'dpia'                 => $fields,
            'exists'               => $dpia !== null,
            'status'               => $status,
            'is_approved'          => $status === DpiaRules::STATUS_APPROVED,
            'can_edit'             => $canEdit,
            'form_url'             => self::getFormURLForActivity(),
            'report_url'           => self::getReportURL($activityId),
            'criteria_labels'      => self::getCriteriaLabels(),
            'criteria'             => $criteria,
            'criteria_count'       => count($criteria),
            'criteria_threshold'   => DpiaRules::CRITERIA_THRESHOLD,
            'is_required'          => DpiaRules::isRequired($criteria),
            'risks'                => $rows,
            'scale_labels'         => self::getScaleLabels(),
            'scales'               => DpiaRules::RISK_SCALES,
            'level_labels'         => self::getLevelLabels(),
            'needs_consultation'   => DpiaRules::needsPriorConsultation($risks),
            'missing'              => array_values(array_intersect_key(
                self::getMissingLabels(),
                array_flip(DpiaRules::missingForApproval($fields))
            )),
            'record_status_label'  => ProcessingActivity::getDpiaStatuses()[$recordStatus] ?? $recordStatus,
            'suggested_status'     => self::suggestedLabel($recordStatus, $criteria, $status),
            'dpo_name'             => (int) ($fields['users_id_dpo'] ?? 0) > 0
                ? getUserName((int) $fields['users_id_dpo']) : '',
            'approval_name'        => (int) ($fields['users_id_approval'] ?? 0) > 0
                ? getUserName((int) $fields['users_id_approval']) : '',
            'risk'                 => self::linkedRisk($fields),
            'can_send_to_register' => $canEdit && Risk::canCreate() && $dpia !== null && $worst !== null,
        ];
    }

    /**
     * @param list<string> $criteria
     */
    private static function suggestedLabel(string $recordStatus, array $criteria, string $status): string
    {
        $suggested = DpiaRules::suggestedRecordStatus($recordStatus, $criteria, $status);

        return $suggested === null ? '' : (ProcessingActivity::getDpiaStatuses()[$suggested] ?? $suggested);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    private static function linkedRisk(array $fields): ?array
    {
        $riskId = (int) ($fields[Risk::getForeignKeyField()] ?? 0);
        $risk = new Risk();
        if ($riskId <= 0 || !Risk::canView() || !$risk->getFromDB($riskId)) {
            return null;
        }
        $matrix = RiskMatrixConfig::load();

        return [
            'title' => (string) $risk->fields['title'],
            'url'   => Risk::getFormURLWithID($riskId),
            'level' => (new RiskScoringService($matrix))->level(
                (string) $risk->fields['probability'],
                (string) $risk->fields['impact']
            ),
        ];
    }

    /**
     * Rapport PDF, destiné notamment à l'autorité de contrôle.
     */
    public static function renderReport(ProcessingActivity $activity): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/dpia_report.html.twig', [
            'report_title'       => sprintf(
                __('%1$s: %2$s'),
                self::getTypeName(1),
                (string) $activity->fields['name']
            ),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'purpose'            => (string) ($activity->fields['purpose'] ?? ''),
        ] + self::viewData($activity));
    }

    public static function getFormURLForActivity(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/dpia.form.php';
    }

    public static function getReportURL(int $activityId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/dpia.report.php?id=' . $activityId;
    }
}
