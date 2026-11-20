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
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules;
use GlpiPlugin\Grcmanager\Services\Audit\ProgrammeRules;
use GlpiPlugin\Grcmanager\Services\Audit\SamplingPlanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Responsibility\ResponsibilityRules;
use Html;
use Session;

/**
 * Issue #772, lot 2 : plan d'audit de l'organisme de certification.
 *
 * - programme d'échantillonnage de l'organisme sur le cycle de certification de trois ans :
 *   matrice processus x années, chaque case cochée rattachée à l'audit de certification de
 *   l'année ;
 * - onglet « Plan d'audit de l'organisme » d'un audit de certification : processus échantillonnés,
 *   constats précédents de chacun et écrans du plugin qui y répondent.
 *
 * Aucune nouvelle liste ni table : les processus sont ceux du programme d'audit interne (#773,
 * activités de la matrice des responsabilités), et un processus échantillonné est un processus
 * visé par l'audit (AuditProgramme::PROCESSES_TABLE), comme pour un audit interne. Le programme
 * interne ne retient que les audits internes, ce plan que les audits de l'organisme.
 *
 * Droits : ceux de l'audit (lecture pour voir, modification de l'audit concerné pour cocher),
 * entités de la session ; un processus coché appartient à l'entité de l'audit (ou la couvre).
 */
class CertificationSampling extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Audit plan of the certification body', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-table-options';
    }

    public static function canView(): bool
    {
        return Audit::canView();
    }

    public static function canUpdate(): bool
    {
        return Audit::canUpdate();
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/certificationsampling.php';
    }

    public static function getFormURL($full = true)
    {
        return self::getSearchURL($full);
    }

    /** Sous-page de la rubrique « Audits et amélioration » (Menu::sections()). */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Données
    // ---------------------------------------------------------------------------------------------

    /**
     * Processus des entités de la session (activités du SMSI), avec leurs mesures.
     *
     * @return array<int, array{id: int, label: string, codes: list<string>, default_key: string}>
     */
    public static function processes(): array
    {
        $processes = [];
        foreach (ResponsibilityActivity::visibleActivities() as $row) {
            $processes[(int) $row['id']] = [
                'id'          => (int) $row['id'],
                'label'       => ResponsibilityActivity::label($row),
                'codes'       => ResponsibilityRules::splitCodes((string) ($row['control_codes'] ?? '')),
                'default_key' => (string) ($row['default_key'] ?? ''),
            ];
        }

        return $processes;
    }

    /**
     * Audits de l'organisme des entités de la session : identifiant, statut et date retenue.
     *
     * @param list<array<string, mixed>> $external résultat de CertificationFinding::externalAudits()
     * @return list<array{id: int, status: string, date: string}>
     */
    private static function auditDates(array $external): array
    {
        $audits = [];
        foreach ($external as $audit) {
            $audits[] = ['id' => $audit['id'], 'status' => $audit['status_key'], 'date' => $audit['date_value']];
        }

        return $audits;
    }

    /**
     * Programme d'échantillonnage du cycle qui commence en $start.
     *
     * @return array<string, mixed>
     */
    public static function plan(int $start, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $years = ProgrammeRules::cycleYears($start);
        $all = CertificationFinding::externalAudits();
        $external = array_column($all, null, 'id');
        $dates = self::auditDates($all);
        $byYear = Rules::auditsByYear($dates, $years);

        $columns = [];
        $sampled = [];
        $auditIds = [];
        $choices = [];
        $editable = [];
        $audit = new Audit();
        foreach ($byYear as $year => $audits) {
            foreach ($audits as $row) {
                $id = $row['id'];
                $auditIds[] = $id;
                $sampled[$id] = AuditProgramme::processIds($id);
                $canEdit = $audit->getFromDB($id) && $audit->can($id, UPDATE);
                $editable[$id] = $canEdit;
                $choices[$id] = $canEdit ? array_keys(AuditProgramme::processChoices($audit)) : [];
                $columns[] = $external[$id] + ['year' => $year, 'can_edit' => $canEdit];
            }
        }

        $processes = self::processes();
        $matrix = Rules::matrix(array_column($processes, 'label', 'id'), $sampled, $auditIds);
        $openByProcess = self::openFindingsByProcess($processes, array_column($all, 'id'));
        foreach ($matrix['rows'] as &$row) {
            $row['open_findings'] = $openByProcess[$row['id']] ?? 0;
            $row['findings_url'] = CertificationFinding::getSearchURL() . '?process=' . $row['id'];
            $row['editable'] = [];
            foreach ($auditIds as $auditId) {
                $row['editable'][$auditId] = in_array($row['id'], $choices[$auditId], true);
            }
        }
        unset($row);

        return $matrix + [
            'start'        => $start,
            'years'        => $years,
            'current_year' => (int) substr($today, 0, 4),
            'by_year'      => array_map(static fn (array $list): int => count($list), $byYear),
            'columns'      => $columns,
            'can_edit_any' => in_array(true, $editable, true),
            'year_audit'   => Rules::auditOfTheYear($dates, $today),
        ];
    }

    /**
     * Nombre de constats de l'organisme non levés, par processus.
     *
     * @param array<int, array{id: int, label: string, codes: list<string>, default_key: string}> $processes
     * @param list<int> $auditIds
     * @return array<int, int>
     */
    private static function openFindingsByProcess(array $processes, array $auditIds): array
    {
        $counts = [];
        foreach (CertificationFinding::findings($auditIds) as $finding) {
            if (!CertificationAuditRules::isOpenState($finding['lift_state'])) {
                continue;
            }
            foreach ($processes as $process) {
                if (self::concerns($finding, $process)) {
                    $counts[$process['id']] = ($counts[$process['id']] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $finding ligne de CertificationFinding::findings()
     * @param array{id: int, label: string, codes: list<string>, default_key: string} $process
     */
    public static function concerns(array $finding, array $process): bool
    {
        return Rules::findingConcernsProcess(
            (string) $finding['process'],
            (string) $finding['requirement'],
            $process['label'],
            $process['codes']
        );
    }

    /**
     * Processus visible des entités de la session, null sinon.
     *
     * @return array{id: int, label: string, codes: list<string>, default_key: string}|null
     */
    public static function process(int $processId): ?array
    {
        return $processId > 0 ? (self::processes()[$processId] ?? null) : null;
    }

    /**
     * Écrans du plugin qui répondent à un processus : ses mesures de l'Annexe A (fiche de la mesure
     * dans la SoA de l'entité) et, pour une activité proposée par défaut, les registres qui en
     * produisent les preuves. Seuls les écrans que la personne peut ouvrir sont donnés.
     *
     * @param array{id: int, label: string, codes: list<string>, default_key: string} $process
     * @param array<string, int> $controlIds code => mesure de la SoA de l'entité de l'audit
     * @return array{controls: list<array{code: string, title: string, url: string}>,
     *               screens: list<array{label: string, url: string, icon: string}>}
     */
    public static function screens(array $process, array $controlIds): array
    {
        $controls = [];
        $canControls = Control::canView();
        foreach ($process['codes'] as $code) {
            $controlId = $controlIds[$code] ?? 0;
            $controls[] = [
                'code'  => $code,
                'title' => Control::getControlTitle($code),
                'url'   => $canControls && $controlId > 0 ? Control::getFormURLWithID($controlId) : '',
            ];
        }
        $screens = [];
        foreach (Rules::screensFor($process['default_key']) as $itemtype) {
            if (!class_exists($itemtype) || !is_a($itemtype, CommonGLPI::class, true) || !$itemtype::canView()) {
                continue;
            }
            $screens[] = [
                'label' => (string) $itemtype::getTypeName(Session::getPluralNumber()),
                'url'   => (string) $itemtype::getSearchURL(),
                'icon'  => method_exists($itemtype, 'getIcon') ? (string) $itemtype::getIcon() : 'ti ti-link',
            ];
        }

        return ['controls' => $controls, 'screens' => $screens];
    }

    /**
     * Processus échantillonnés d'un audit de l'organisme, chacun avec ses constats des audits de
     * l'organisme antérieurs et les écrans qui y répondent.
     *
     * @return list<array<string, mixed>>
     */
    public static function sampledProcesses(Audit $audit): array
    {
        $processes = self::processes();
        $all = CertificationFinding::externalAudits();
        $dates = self::auditDates($all);
        $date = ProgrammeRules::auditDate(
            $audit->fields['actual_date'] ?? null,
            $audit->fields['planned_date'] ?? null
        );
        $previous = Rules::previousAudits($dates, $audit->getID(), $date);
        $findings = CertificationFinding::findings($previous);
        $controlIds = EntityPerimeter::controlIdsByCode((int) ($audit->fields['entities_id'] ?? 0));
        $titles = array_column($all, 'title', 'id');

        $rows = [];
        foreach (AuditProgramme::processIds($audit->getID()) as $processId) {
            $process = $processes[$processId] ?? null;
            if ($process === null) {
                continue;
            }
            $related = [];
            $open = 0;
            foreach ($findings as $finding) {
                if (self::concerns($finding, $process)) {
                    $finding['audit_title'] = $titles[$finding['audit_id']] ?? '';
                    $related[] = $finding;
                    $open += (int) CertificationAuditRules::isOpenState($finding['lift_state']);
                }
            }
            $rows[] = $process + self::screens($process, $controlIds) + [
                'findings'      => $related,
                'open_findings' => $open,
                'findings_url'  => CertificationFinding::getSearchURL() . '?process=' . $processId,
                'raci_url'      => ResponsibilityActivity::canView()
                    ? ResponsibilityActivity::getFormURLWithID($processId)
                    : '',
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------------------------------------
    // Enregistrement
    // ---------------------------------------------------------------------------------------------

    /**
     * Enregistre les cases cochées de la matrice. Chaque audit doit être un audit de l'organisme
     * que la personne peut modifier (droit et entité, check() du cœur, qui refuse sinon) ; seuls
     * les processus de AuditProgramme::processChoices() sont retenus.
     *
     * @param array<mixed> $auditIds audits affichés dans la matrice
     * @param array<mixed> $sampled audit => processus cochés
     */
    public static function saveMatrix(array $auditIds, array $sampled): int
    {
        $saved = 0;
        foreach (array_unique(array_map('intval', $auditIds)) as $auditId) {
            $audit = self::editableAudit($auditId);
            $processes = $sampled[$auditId] ?? [];
            AuditProgramme::setProcesses($audit, is_array($processes) ? $processes : []);
            $saved++;
        }

        return $saved;
    }

    /**
     * Audit de l'organisme modifiable par la personne ; refuse la requête sinon (404 pour un audit
     * inconnu, 403 sans droit, hors entité ou pour un audit interne).
     */
    public static function editableAudit(int $auditId): Audit
    {
        $audit = new Audit();
        $audit->check($auditId, UPDATE);
        if (!CertificationAuditRules::isExternal((string) ($audit->fields['audit_type'] ?? ''))) {
            Html::displayRightError();
        }

        return $audit;
    }

    // ---------------------------------------------------------------------------------------------
    // Onglet de l'audit de certification
    // ---------------------------------------------------------------------------------------------

    private static function isExternal(Audit $audit): bool
    {
        return CertificationAuditRules::isExternal((string) ($audit->fields['audit_type'] ?? ''));
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !Audit::canView() || !self::isExternal($item)) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(),
            count(AuditProgramme::processIds($item->getID())),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Audit || !$item->can($item->getID(), READ) || !self::isExternal($item)) {
            return false;
        }
        self::showForAudit($item);

        return true;
    }

    public static function showForAudit(Audit $audit): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/certification_sampling_tab.html.twig', [
            'audit'     => $audit,
            'can_edit'  => $audit->can($audit->getID(), UPDATE),
            'action'    => self::getFormURL(),
            'choices'   => AuditProgramme::processChoices($audit),
            'selected'  => AuditProgramme::processIds($audit->getID()),
            'processes' => self::sampledProcesses($audit),
            'plan_url'  => self::getSearchURL(),
            'raci_url'  => ResponsibilityActivity::canView() ? ResponsibilityActivity::getSearchURL() : '',
            'lift_states' => CertificationFinding::getLiftStates(),
        ]);
    }
}
