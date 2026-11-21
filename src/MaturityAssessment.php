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

use GlpiPlugin\Grcmanager\Install\MaturityCampaignTables;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityGapRules as Rules;
use Session;
use User;

/**
 * Issue #603 : lignes d'une campagne de maturité, une par mesure applicable de la SoA de l'entité :
 * niveau actuel, niveau cible, échéance, justification, responsable et action ouverte.
 *
 * Les niveaux sont facultatifs : ils ne modifient ni l'état de mise en œuvre de la mesure ni le
 * score de maturité global. Une action se crée depuis un écart : le bouton ouvre une
 * non-conformité pré-remplie (piste d'amélioration) que l'utilisateur relit et enregistre lui-même.
 */
class MaturityAssessment
{
    public const TABLE = MaturityCampaignTables::ASSESSMENTS;

    /**
     * Lignes d'une nouvelle campagne : les mesures applicables de la SoA de l'entité. La cible,
     * l'échéance, la justification et le responsable de la campagne précédente sont repris ; le
     * niveau actuel reste à évaluer.
     */
    public static function createLines(MaturityCampaign $campaign, int $previousId): void
    {
        global $DB;

        $previous = [];
        foreach ($previousId > 0 ? self::getLines($previousId) : [] as $line) {
            $previous[(string) $line['code']] = $line;
        }
        $now = date('Y-m-d H:i:s');
        foreach (self::applicableCodes((int) $campaign->fields['entities_id']) as $code) {
            $old = $previous[$code] ?? [];
            $DB->updateOrInsert(self::TABLE, [
                'target_level'  => Rules::normalizeLevel($old['target_level'] ?? null),
                'target_date'   => Rules::normalizeDate($old['target_date'] ?? null),
                'justification' => (string) ($old['justification'] ?? ''),
                'users_id'      => (int) ($old['users_id'] ?? 0),
                'date_creation' => $now,
                'date_mod'      => $now,
            ], [MaturityCampaign::FK => $campaign->getID(), 'code' => $code]);
        }
    }

    /**
     * Mesures applicables (totalement ou partiellement) de la SoA suivie par l'entité.
     *
     * @return list<string>
     */
    public static function applicableCodes(int $entityId): array
    {
        global $DB;

        $codes = [];
        foreach (
            $DB->request([
                'SELECT' => ['code'],
                'FROM'   => Control::getTable(),
                'WHERE'  => EntityPerimeter::controlCriteria($entityId) + ['applicability' => ['yes', 'partial']],
            ]) as $row
        ) {
            $codes[] = (string) $row['code'];
        }
        usort($codes, 'strnatcmp');

        return $codes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getLines(int $campaignId): array
    {
        global $DB;

        $lines = array_values(iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [MaturityCampaign::FK => $campaignId],
        ]), false));
        usort($lines, static fn (array $a, array $b): int => strnatcmp((string) $a['code'], (string) $b['code']));

        return $lines;
    }

    /**
     * Niveaux actuels d'une campagne, par code de mesure.
     *
     * @return array<string, ?int>
     */
    public static function currentLevels(int $campaignId): array
    {
        $levels = [];
        foreach (self::getLines($campaignId) as $line) {
            $levels[(string) $line['code']] = Rules::normalizeLevel($line['current_level']);
        }

        return $levels;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getLine(int $lineId): ?array
    {
        global $DB;

        if ($lineId <= 0) {
            return null;
        }
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $lineId]])->current();

        return $row === null ? null : $row;
    }

    public static function lineTitle(string $code): string
    {
        return trim($code . ' ' . Control::getControlTitle($code));
    }

    /**
     * Grille enregistrée d'un coup : seules les lignes de la campagne sont modifiées, les autres
     * identifiants sont ignorés. Renvoie le nombre de lignes modifiées.
     *
     * @param array<mixed> $posted identifiant de ligne => current_level, target_level, target_date,
     *     justification, users_id
     */
    public static function updateGrid(MaturityCampaign $campaign, array $posted): int
    {
        global $DB;

        $lines = [];
        foreach (self::getLines($campaign->getID()) as $line) {
            $lines[(int) $line['id']] = $line;
        }
        $changed = 0;
        $now = date('Y-m-d H:i:s');
        $users = [];
        foreach ($posted as $lineId => $values) {
            $lineId = (int) $lineId;
            if (!isset($lines[$lineId]) || !is_array($values)) {
                continue;
            }
            $usersId = max(0, (int) ($values['users_id'] ?? 0));
            if ($usersId > 0 && !isset($users[$usersId])) {
                $users[$usersId] = self::isActiveUser($usersId);
            }
            $fields = [
                'current_level' => Rules::normalizeLevel($values['current_level'] ?? null),
                'target_level'  => Rules::normalizeLevel($values['target_level'] ?? null),
                'target_date'   => Rules::normalizeDate($values['target_date'] ?? null),
                'justification' => Rules::cleanText($values['justification'] ?? ''),
                'users_id'      => $usersId > 0 && $users[$usersId] ? $usersId : 0,
            ];
            $old = $lines[$lineId];
            $same = Rules::normalizeLevel($old['current_level']) === $fields['current_level']
                && Rules::normalizeLevel($old['target_level']) === $fields['target_level']
                && Rules::normalizeDate($old['target_date']) === $fields['target_date']
                && (string) $old['justification'] === $fields['justification']
                && (int) $old['users_id'] === $fields['users_id'];
            if ($same) {
                continue;
            }
            $DB->update(self::TABLE, $fields + [
                'users_id_lastupdater' => (int) Session::getLoginUserID(),
                'date_mod'             => $now,
            ], ['id' => $lineId]);
            $changed++;
        }
        if ($changed > 0) {
            $DB->update(MaturityCampaign::getTable(), ['date_mod' => $now], ['id' => $campaign->getID()]);
        }

        return $changed;
    }

    private static function isActiveUser(int $usersId): bool
    {
        $user = new User();

        return $user->getFromDB($usersId) && (int) $user->fields['is_active'] === 1
            && (int) $user->fields['is_deleted'] === 0;
    }

    /** Campagne purgée : ses lignes disparaissent avec elle (les actions ouvertes restent). */
    public static function purgeCampaign(int $campaignId): void
    {
        global $DB;

        $DB->delete(self::TABLE, [MaturityCampaign::FK => $campaignId]);
    }

    // ---------------------------------------------------------------------------------------------
    // Action ouverte depuis un écart
    // ---------------------------------------------------------------------------------------------

    /**
     * Valeurs proposées pour l'action d'un écart (fiche pré-remplie, relue et enregistrée par
     * l'utilisateur). Null si la ligne n'a pas d'écart, a déjà une action, ou si l'utilisateur ne
     * peut pas modifier la campagne.
     *
     * @return array<string, mixed>|null
     */
    public static function findingDraft(int $lineId): ?array
    {
        $line = self::getLine($lineId);
        if ($line === null || (int) $line['plugin_grcmanager_nonconformities_id'] > 0) {
            return null;
        }
        $current = Rules::normalizeLevel($line['current_level']);
        $target = Rules::normalizeLevel($line['target_level']);
        $preset = Rules::findingPreset(Rules::gap($current, $target));
        $campaign = MaturityCampaign::load((int) $line[MaturityCampaign::FK]);
        if ($preset === null || $campaign === null || !$campaign->can($campaign->getID(), UPDATE)) {
            return null;
        }
        $code = (string) $line['code'];
        $labels = MaturityCampaign::scaleLabels();
        $parts = [
            sprintf(
                __('Maturity campaign: %s', 'grcmanager'),
                $campaign->getName()
            ),
            sprintf(
                __('Current level: %1$d (%2$s). Target level: %3$d (%4$s).', 'grcmanager'),
                $current,
                $labels[$current],
                $target,
                $labels[$target]
            ),
        ];
        $date = Rules::normalizeDate($line['target_date']);
        if ($date !== null) {
            $parts[] = sprintf(__('Target date: %s', 'grcmanager'), \Html::convDate($date));
        }
        if (trim((string) $line['justification']) !== '') {
            $parts[] = __('Justification', 'grcmanager') . "\n" . trim((string) $line['justification']);
        }

        return $preset + [
            'line_id'               => $lineId,
            'line_title'            => self::lineTitle($code),
            'entities_id'           => (int) $campaign->fields['entities_id'],
            'is_recursive'          => (int) $campaign->fields['is_recursive'],
            'requirement_reference' => $code,
            'title'                 => mb_substr(sprintf(
                __('Maturity gap %1$s: from level %2$d to level %3$d', 'grcmanager'),
                self::lineTitle($code),
                $current,
                $target
            ), 0, 255),
            'description'           => implode("\n\n", $parts),
            'due_date'              => $date,
            'users_id'              => (int) $line['users_id'],
        ];
    }

    /**
     * Après l'enregistrement par l'utilisateur d'une action ouverte depuis un écart : la ligne y est
     * liée si l'action est de l'entité de la campagne et si la ligne n'en a pas déjà une.
     */
    public static function attachFinding(int $lineId, Nonconformity $finding): bool
    {
        global $DB;

        $line = self::getLine($lineId);
        $campaign = $line === null ? null : MaturityCampaign::load((int) $line[MaturityCampaign::FK]);
        if (
            $line === null || $campaign === null
            || (int) $line['plugin_grcmanager_nonconformities_id'] > 0
            || (int) ($finding->fields['entities_id'] ?? -1) !== (int) $campaign->fields['entities_id']
            || !$campaign->can($campaign->getID(), UPDATE)
        ) {
            return false;
        }

        return (bool) $DB->update(
            self::TABLE,
            ['plugin_grcmanager_nonconformities_id' => $finding->getID(), 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $lineId]
        );
    }

    /**
     * Écarts liés à une action (fiche de la non-conformité), si l'utilisateur peut lire la campagne.
     *
     * @return list<array{title: string, campaign: string, url: string}>
     */
    public static function linesForFinding(int $findingId): array
    {
        global $DB;

        $lines = [];
        if ($findingId <= 0 || !MaturityCampaign::canView()) {
            return $lines;
        }
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_nonconformities_id' => $findingId],
                'ORDER' => ['id'],
            ]) as $line
        ) {
            $campaign = MaturityCampaign::load((int) $line[MaturityCampaign::FK]);
            if ($campaign === null || !$campaign->can($campaign->getID(), READ)) {
                continue;
            }
            $lines[] = [
                'title'    => self::lineTitle((string) $line['code']),
                'campaign' => $campaign->getName(),
                'url'      => MaturityCampaign::getFormURLWithID($campaign->getID())
                    . '&forcetab=' . rawurlencode(MaturityCampaign::class . '$2'),
            ];
        }

        return $lines;
    }

    /** Action purgée : les lignes restent, sans lien. */
    public static function unlinkFinding(int $findingId): void
    {
        global $DB;

        $DB->update(self::TABLE, ['plugin_grcmanager_nonconformities_id' => 0], [
            'plugin_grcmanager_nonconformities_id' => $findingId,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Présentation
    // ---------------------------------------------------------------------------------------------

    /**
     * Référentiels activés (hors ISO 27001, qui couvre toutes les mesures) dont chaque mesure relève,
     * pour filtrer les écarts.
     *
     * @return array<string, string> clé => nom
     */
    public static function frameworkOptions(): array
    {
        $enabled = FrameworkConfig::enabled();
        $options = [];
        foreach (PilotageCards::frameworkFilters() as $label => [$key]) {
            if ($key !== FrameworkSelection::ISO27001 && in_array($key, $enabled, true)) {
                $options[$key] = (string) $label;
            }
        }

        return $options;
    }

    /**
     * Lignes prêtes pour les onglets et le rapport, avec le niveau de la campagne précédente.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(MaturityCampaign $campaign): array
    {
        global $DB;

        $lines = self::getLines($campaign->getID());
        $previousId = MaturityCampaign::previousCampaignId($campaign);
        $previous = $previousId > 0 ? self::currentLevels($previousId) : [];
        $controls = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code', 'theme', 'implementation_status'],
                'FROM'   => Control::getTable(),
                'WHERE'  => EntityPerimeter::controlCriteria((int) $campaign->fields['entities_id']),
            ]) as $row
        ) {
            $controls[(string) $row['code']] = $row;
        }
        $findingIds = array_values(array_filter(array_map(
            static fn (array $l): int => (int) $l['plugin_grcmanager_nonconformities_id'],
            $lines
        )));
        $findings = [];
        if ($findingIds !== [] && Nonconformity::canView()) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'title', 'status'],
                    'FROM'   => Nonconformity::getTable(),
                    'WHERE'  => array_merge(
                        ['id' => $findingIds],
                        EntityPerimeter::criteria(Nonconformity::getTable())
                    ),
                ]) as $row
            ) {
                $findings[(int) $row['id']] = $row;
            }
        }
        $filters = [];
        foreach (PilotageCards::frameworkFilters() as [$key, $covers]) {
            $filters[$key] = $covers;
        }
        $frameworks = self::frameworkOptions();
        $themes = Control::getThemes();
        $statuses = Control::getImplementationStatuses();
        $labels = MaturityCampaign::scaleLabels();
        $ncStatuses = Nonconformity::getStatuses();
        $today = date('Y-m-d');
        $rows = [];
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $code = (string) $line['code'];
            $current = Rules::normalizeLevel($line['current_level']);
            $target = Rules::normalizeLevel($line['target_level']);
            $gap = Rules::gap($current, $target);
            $date = Rules::normalizeDate($line['target_date']);
            $theme = (string) ($controls[$code]['theme'] ?? '');
            $status = (string) ($controls[$code]['implementation_status'] ?? '');
            $findingId = (int) $line['plugin_grcmanager_nonconformities_id'];
            $owner = (int) $line['users_id'];
            $covered = [];
            foreach (array_keys($frameworks) as $key) {
                if (isset($filters[$key]) && ($filters[$key])($code)) {
                    $covered[] = $key;
                }
            }
            $before = $previous[$code] ?? null;
            $rows[] = [
                'id'              => $id,
                'code'            => $code,
                'title'           => Control::getControlTitle($code),
                'control_id'      => (int) ($controls[$code]['id'] ?? 0),
                'theme'           => $theme,
                'theme_label'     => $themes[$theme] ?? $theme,
                'status_label'    => $statuses[$status] ?? $status,
                'current'         => $current,
                'current_label'   => $current === null ? '' : $labels[$current],
                'current_class'   => Rules::levelBadgeClass($current),
                'target'          => $target,
                'target_label'    => $target === null ? '' : $labels[$target],
                'target_class'    => Rules::levelBadgeClass($target),
                'gap'             => $gap,
                'gap_class'       => Rules::gapBadgeClass($gap),
                'target_date'     => $date,
                'target_date_label' => $date === null ? '' : (string) \Html::convDate($date),
                'overdue'         => $date !== null && $date < $today && (int) $gap > 0,
                'justification'   => (string) $line['justification'],
                'users_id'        => $owner,
                'owner'           => $owner > 0 ? getUserName($owner) : '',
                'previous'        => $before,
                'previous_label'  => $before === null ? '' : $labels[$before],
                'frameworks'      => $covered,
                'can_raise'       => (int) $gap > 0,
                'finding_id'      => isset($findings[$findingId]) ? $findingId : 0,
                'finding_title'   => (string) ($findings[$findingId]['title'] ?? ''),
                'finding_status'  => (string) ($ncStatuses[(string) ($findings[$findingId]['status'] ?? '')] ?? ''),
                'finding_url'     => isset($findings[$findingId]) ? Nonconformity::getFormURLWithID($findingId) : '',
            ];
        }

        return $rows;
    }
}
