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

use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\PhysicalSecurityTables;
use GlpiPlugin\Grcmanager\Services\Physical\PhysicalSecurityRules as Rules;
use Html;
use Session;

/**
 * Issue #778 : grille de l'évaluation de la sécurité physique d'un site, une ligne par mesure A.7.1 à
 * A.7.14 : état, constat, preuves examinées (texte et documents de l'évaluation), action CAPA.
 *
 * Modèle proche de la grille d'audit (AuditChecklist), sans la réutiliser : celle-ci appartient à
 * un audit et à son équipe, avec les résultats d'un audit (non-conformité mineure ou majeure,
 * observation...). Ici, l'état d'une mesure sur un site et l'évaluation, lue avec ses droits.
 *
 * Une action se crée depuis une ligne partielle ou non conforme : le bouton ouvre une
 * non-conformité pré-remplie que l'utilisateur relit et enregistre lui-même ; rien n'est créé
 * automatiquement.
 */
class PhysicalSecurityCheck
{
    public const TABLE = PhysicalSecurityTables::CHECKS;
    public const DOCUMENTS_TABLE = PhysicalSecurityTables::CHECK_DOCUMENTS;
    public const FK = 'plugin_grcmanager_physicalsecuritychecks_id';

    /** Les 14 lignes, à la création de l'évaluation (non applicable si la SoA exclut la mesure). */
    public static function createLines(PhysicalSecurityAssessment $assessment): void
    {
        global $DB;

        $excluded = [];
        foreach (
            $DB->request([
                'SELECT' => ['code'],
                'FROM'   => Control::getTable(),
                'WHERE'  => EntityPerimeter::controlCriteria((int) $assessment->fields['entities_id']) + [
                    'code'          => Rules::CONTROL_CODES,
                    'applicability' => 'no',
                ],
            ]) as $row
        ) {
            $excluded[(string) $row['code']] = true;
        }
        $now = date('Y-m-d H:i:s');
        foreach (Rules::CONTROL_CODES as $code) {
            $DB->updateOrInsert(self::TABLE, [
                'state'         => isset($excluded[$code]) ? Rules::STATE_NOT_APPLICABLE : Rules::STATE_NOT_ASSESSED,
                'date_creation' => $now,
                'date_mod'      => $now,
            ], [PhysicalSecurityAssessment::FK => $assessment->getID(), 'code' => $code]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getLines(int $assessmentId): array
    {
        global $DB;

        $lines = array_values(iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [PhysicalSecurityAssessment::FK => $assessmentId],
        ]), false));
        usort($lines, static fn (array $a, array $b): int => strnatcmp((string) $a['code'], (string) $b['code']));

        return $lines;
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

    public static function loadAssessment(int $assessmentId): ?PhysicalSecurityAssessment
    {
        $assessment = new PhysicalSecurityAssessment();

        return $assessmentId > 0 && $assessment->getFromDB($assessmentId) ? $assessment : null;
    }

    public static function lineTitle(string $code): string
    {
        return trim($code . ' ' . Control::getControlTitle($code));
    }

    /**
     * Documents de l'évaluation (onglet Documents : photos, procès-verbaux), cités comme preuves.
     *
     * @return array<int, string>
     */
    public static function assessmentDocuments(int $assessmentId): array
    {
        global $DB;

        $documents = [];
        foreach (
            $DB->request([
                'SELECT'     => ['d.id', 'd.name', 'd.filename'],
                'FROM'       => Document_Item::getTable() . ' AS di',
                'INNER JOIN' => [
                    Document::getTable() . ' AS d' => ['FKEY' => ['di' => 'documents_id', 'd' => 'id']],
                ],
                'WHERE'      => ['di.itemtype' => PhysicalSecurityAssessment::class, 'di.items_id' => $assessmentId],
                'ORDER'      => ['d.name', 'd.id'],
            ]) as $row
        ) {
            $name = (string) ($row['name'] ?: $row['filename']);
            $documents[(int) $row['id']] = $name !== '' ? $name : '#' . (int) $row['id'];
        }

        return $documents;
    }

    /**
     * @param list<int> $lineIds
     * @return array<int, list<int>> ligne => documents
     */
    public static function lineDocuments(array $lineIds): array
    {
        global $DB;

        $links = [];
        if ($lineIds === []) {
            return $links;
        }
        foreach (
            $DB->request([
                'SELECT' => [self::FK, 'documents_id'],
                'FROM'   => self::DOCUMENTS_TABLE,
                'WHERE'  => [self::FK => $lineIds],
                'ORDER'  => ['id'],
            ]) as $row
        ) {
            $links[(int) $row[self::FK]][] = (int) $row['documents_id'];
        }

        return $links;
    }

    /**
     * Ligne d'une évaluation : état, constat, preuves (texte et documents de l'évaluation).
     *
     * @param array<string, mixed> $input
     */
    public static function updateLine(PhysicalSecurityAssessment $assessment, int $lineId, array $input): bool
    {
        global $DB;

        $line = self::getLine($lineId);
        if ($line === null || (int) $line[PhysicalSecurityAssessment::FK] !== $assessment->getID()) {
            return false;
        }
        $DB->update(self::TABLE, [
            'state'                => Rules::normalizeState($input['state'] ?? ''),
            'finding'              => Rules::cleanText($input['finding'] ?? ''),
            'evidence'             => Rules::cleanText($input['evidence'] ?? ''),
            'users_id_lastupdater' => (int) Session::getLoginUserID(),
            'date_mod'             => date('Y-m-d H:i:s'),
        ], ['id' => $lineId]);

        $documents = array_intersect(
            array_unique(array_map('intval', (array) ($input['documents'] ?? []))),
            array_keys(self::assessmentDocuments($assessment->getID()))
        );
        $DB->delete(self::DOCUMENTS_TABLE, [self::FK => $lineId]);
        foreach ($documents as $documentsId) {
            $DB->insert(self::DOCUMENTS_TABLE, [
                self::FK        => $lineId,
                'documents_id'  => $documentsId,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        }
        $DB->update(PhysicalSecurityAssessment::getTable(), ['date_mod' => date('Y-m-d H:i:s')], [
            'id' => $assessment->getID(),
        ]);

        return true;
    }

    /** Évaluation purgée : sa grille disparaît avec elle (les actions CAPA restent). */
    public static function purgeAssessment(int $assessmentId): void
    {
        global $DB;

        $ids = array_column(self::getLines($assessmentId), 'id');
        if ($ids !== []) {
            $DB->delete(self::DOCUMENTS_TABLE, [self::FK => $ids]);
            $DB->delete(self::TABLE, ['id' => $ids]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Action CAPA créée depuis une ligne
    // ---------------------------------------------------------------------------------------------

    /**
     * Valeurs proposées pour la non-conformité d'une ligne (fiche pré-remplie, relue et enregistrée
     * par l'utilisateur). Null si la ligne n'appelle pas d'action, en a déjà une, ou si l'utilisateur
     * ne peut pas modifier l'évaluation.
     *
     * @return array<string, mixed>|null
     */
    public static function findingDraft(int $lineId): ?array
    {
        $line = self::getLine($lineId);
        if ($line === null || (int) $line['plugin_grcmanager_nonconformities_id'] > 0) {
            return null;
        }
        $preset = Rules::findingPreset((string) $line['state']);
        $assessment = self::loadAssessment((int) $line[PhysicalSecurityAssessment::FK]);
        if ($preset === null || $assessment === null || !$assessment->can($assessment->getID(), UPDATE)) {
            return null;
        }
        $code = (string) $line['code'];
        $title = self::lineTitle($code);
        $site = PhysicalSecurityAssessment::siteName((int) $assessment->fields['locations_id']);
        $parts = [];
        foreach (
            [
                __('Site', 'grcmanager')              => $site,
                __('Finding', 'grcmanager')           => (string) $line['finding'],
                __('Evidence examined', 'grcmanager') => (string) $line['evidence'],
            ] as $heading => $text
        ) {
            if (trim($text) !== '') {
                $parts[] = $heading . "\n" . trim($text);
            }
        }

        return $preset + [
            'line_id'               => $lineId,
            'line_title'            => $title,
            'entities_id'           => (int) $assessment->fields['entities_id'],
            'is_recursive'          => (int) $assessment->fields['is_recursive'],
            'locations_id'          => (int) $assessment->fields['locations_id'],
            'requirement_reference' => $code,
            'title'                 => mb_substr(sprintf(__('%1$s: %2$s', 'grcmanager'), $site, $title), 0, 255),
            'description'           => implode("\n\n", $parts),
        ];
    }

    /**
     * Après l'enregistrement par l'utilisateur d'une action ouverte depuis une ligne : la ligne y est
     * liée si l'action est de l'entité de l'évaluation et si la ligne n'en a pas déjà une.
     */
    public static function attachFinding(int $lineId, Nonconformity $finding): bool
    {
        global $DB;

        $line = self::getLine($lineId);
        $assessment = $line === null ? null : self::loadAssessment((int) $line[PhysicalSecurityAssessment::FK]);
        if (
            $line === null || $assessment === null
            || (int) $line['plugin_grcmanager_nonconformities_id'] > 0
            || (int) ($finding->fields['entities_id'] ?? -1) !== (int) $assessment->fields['entities_id']
            || !$assessment->can($assessment->getID(), UPDATE)
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
     * Lignes liées à une action (fiche de la non-conformité), si l'utilisateur peut lire l'évaluation.
     *
     * @return list<array{title: string, state: string, state_class: string, site: string, url: string}>
     */
    public static function linesForFinding(int $findingId): array
    {
        global $DB;

        $lines = [];
        if ($findingId <= 0 || !PhysicalSecurityAssessment::canView()) {
            return $lines;
        }
        $states = PhysicalSecurity::stateBadges();
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_nonconformities_id' => $findingId],
                'ORDER' => ['id'],
            ]) as $line
        ) {
            $assessment = self::loadAssessment((int) $line[PhysicalSecurityAssessment::FK]);
            if ($assessment === null || !$assessment->can($assessment->getID(), READ)) {
                continue;
            }
            $state = $states[Rules::normalizeState($line['state'])];
            $lines[] = [
                'title'       => self::lineTitle((string) $line['code']),
                'state'       => $state[0],
                'state_class' => $state[1],
                'site'        => $assessment->getName(),
                'url'         => PhysicalSecurityAssessment::getFormURLWithID($assessment->getID())
                    . '&forcetab=' . rawurlencode(PhysicalSecurityAssessment::class . '$1'),
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
    // Affichage
    // ---------------------------------------------------------------------------------------------

    /**
     * Lignes prêtes pour l'onglet, avec l'état de l'évaluation précédente du site.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(PhysicalSecurityAssessment $assessment): array
    {
        global $DB;

        $lines = self::getLines($assessment->getID());
        $documents = self::assessmentDocuments($assessment->getID());
        $links = self::lineDocuments(array_map(static fn (array $l): int => (int) $l['id'], $lines));
        $previous = [];
        $previousId = PhysicalSecurityAssessment::previousAssessmentId($assessment);
        if ($previousId > 0) {
            foreach (self::getLines($previousId) as $line) {
                $previous[(string) $line['code']] = Rules::normalizeState($line['state']);
            }
        }
        $findingIds = array_filter(array_map(
            static fn (array $l): int => (int) $l['plugin_grcmanager_nonconformities_id'],
            $lines
        ));
        $findings = [];
        if ($findingIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'title', 'status'],
                    'FROM'   => Nonconformity::getTable(),
                    'WHERE'  => array_merge(
                        ['id' => array_values($findingIds)],
                        EntityPerimeter::criteria(Nonconformity::getTable())
                    ),
                ]) as $row
            ) {
                $findings[(int) $row['id']] = $row;
            }
        }
        $states = PhysicalSecurity::stateBadges();
        $ncStatuses = Nonconformity::getStatuses();
        $rows = [];
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $state = Rules::normalizeState($line['state']);
            $findingId = (int) $line['plugin_grcmanager_nonconformities_id'];
            $docs = [];
            foreach ($links[$id] ?? [] as $documentsId) {
                if (isset($documents[$documentsId])) {
                    $docs[] = ['name' => $documents[$documentsId], 'url' => Document::getFormURLWithID($documentsId)];
                }
            }
            $previousState = $previous[(string) $line['code']] ?? null;
            $updater = (int) $line['users_id_lastupdater'];
            $rows[] = [
                'id'             => $id,
                'code'           => (string) $line['code'],
                'title'          => Control::getControlTitle((string) $line['code']),
                'state'          => $state,
                'state_label'    => $states[$state][0],
                'state_class'    => $states[$state][1],
                'state_icon'     => $states[$state][2],
                'previous'       => $previousState === null ? null : $states[$previousState],
                'finding'        => (string) $line['finding'],
                'evidence'       => (string) $line['evidence'],
                'documents'      => $docs,
                'document_ids'   => $links[$id] ?? [],
                'can_raise'      => Rules::needsAction($state),
                'finding_id'     => isset($findings[$findingId]) ? $findingId : 0,
                'finding_title'  => (string) ($findings[$findingId]['title'] ?? ''),
                'finding_status' => (string) ($ncStatuses[(string) ($findings[$findingId]['status'] ?? '')] ?? ''),
                'finding_url'    => isset($findings[$findingId]) ? Nonconformity::getFormURLWithID($findingId) : '',
                'updater'        => $updater > 0 ? getUserName($updater) : '',
                'updated'        => $updater > 0 ? Html::convDateTime((string) $line['date_mod']) : '',
            ];
        }

        return $rows;
    }

    public static function showForAssessment(PhysicalSecurityAssessment $assessment): void
    {
        $rows = self::present($assessment);
        $canEdit = $assessment->can($assessment->getID(), UPDATE);
        $states = PhysicalSecurity::stateBadges();
        TemplateRenderer::getInstance()->display('@grcmanager/physical_security_checklist_tab.html.twig', [
            'assessment_id'      => $assessment->getID(),
            'rows'               => $rows,
            'summary'            => Rules::summary(array_column($rows, 'state')),
            'states'             => $states,
            'state_labels'       => array_map(static fn (array $s): string => $s[0], $states),
            'can_edit'           => $canEdit,
            'can_create_finding' => $canEdit && Nonconformity::canCreate(),
            'finding_url'        => Nonconformity::getFormURL(),
            'action'             => PhysicalSecurityAssessment::getFormURL(),
            'documents'          => self::assessmentDocuments($assessment->getID()),
            'has_previous'       => PhysicalSecurityAssessment::previousAssessmentId($assessment) > 0,
        ]);
    }
}
