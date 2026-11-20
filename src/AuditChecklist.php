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
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules as Rules;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use Html;
use Session;
use User;

/**
 * Issue #606 : grille d'audit interne (onglet « Grille d'audit » de l'audit) et couverture du
 * programme d'audit (ISO 27001, clause 9.2).
 *
 * Chaque ligne vise une exigence du SMSI (articles 4 à 10), une mesure applicable de l'Annexe A de
 * la SoA de l'entité de l'audit, une exigence d'un référentiel personnalisé activé, ou un point
 * libre. L'auditeur y note la question posée, la personne interrogée, les preuves examinées (texte et
 * documents de l'audit), le résultat et un commentaire. Un constat (non-conformité ou observation)
 * se crée depuis une ligne : le bouton ouvre une fiche pré-remplie que l'utilisateur relit et
 * enregistre lui-même ; rien n'est créé automatiquement.
 *
 * Droits : ceux des documents de l'audit. Lire la grille : le droit de lire l'audit ET faire partie
 * de l'équipe de l'audit ou du groupe RSSI (AuditTeam::canAccessDocuments()). La modifier : le droit
 * de modifier l'audit, la même équipe, et un audit ni terminé ni annulé. Les audités de l'équipe
 * la lisent sans la modifier faute du droit de modifier l'audit.
 *
 * Aucune action ne change le statut, la gravité ou l'attribution d'un constat ni de l'audit.
 */
class AuditChecklist extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_auditchecklistitems';
    public const DOCUMENTS_TABLE = 'glpi_plugin_grcmanager_auditchecklistitems_documents';

    public static function getTypeName($nb = 0)
    {
        // Une seule grille par audit : pas de forme plurielle (même msgid que l'onglet).
        return __('Audit checklist', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-details';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/auditchecklist.form.php';
    }

    public static function getPdfURL(int $auditId): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/auditchecklist.pdf.php?id=' . $auditId;
    }

    public static function getCoverageURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/auditcoverage.php';
    }

    /** Onglet « Grille d'audit » de l'audit. */
    public static function getAuditTabURL(int $auditId): string
    {
        return Audit::getFormURLWithID($auditId) . '&forcetab=' . rawurlencode(self::class . '$1');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> résultat => libellé, classes, icône
     */
    public static function getResults(): array
    {
        return [
            Rules::RESULT_COMPLIANT    => [
                __('Compliant', 'grcmanager'), 'bg-green-subtle text-green-emphasis', 'ti-circle-check',
            ],
            Rules::RESULT_NC_MINOR     => [
                __('Minor non-conformity', 'grcmanager'), 'bg-orange-subtle text-orange-emphasis', 'ti-alert-triangle',
            ],
            Rules::RESULT_NC_MAJOR     => [
                __('Major non-conformity', 'grcmanager'), 'bg-red-subtle text-red-emphasis', 'ti-alert-octagon',
            ],
            Rules::RESULT_OBSERVATION  => [
                __('Observation', 'grcmanager'), 'bg-azure-subtle text-azure-emphasis', 'ti-eye',
            ],
            Rules::RESULT_IMPROVEMENT  => [
                __('Opportunity for improvement', 'grcmanager'), 'bg-purple-subtle text-purple-emphasis', 'ti-bulb',
            ],
            Rules::RESULT_NOT_AUDITED  => [
                __('Not audited', 'grcmanager'), 'bg-secondary-subtle text-secondary-emphasis', 'ti-circle-dashed',
            ],
        ];
    }

    /**
     * @return array<string, string> résultat => libellé (listes déroulantes)
     */
    public static function getResultLabels(): array
    {
        return array_map(static fn (array $result): string => $result[0], self::getResults());
    }

    /**
     * @return array<string, string> type de ligne => libellé
     */
    public static function getRefTypes(): array
    {
        return [
            Rules::TYPE_CLAUSE  => __('ISMS requirement', 'grcmanager'),
            Rules::TYPE_CONTROL => __('Annex A control', 'grcmanager'),
            Rules::TYPE_CUSTOM  => __('Framework requirement', 'grcmanager'),
            Rules::TYPE_FREE    => __('Free item', 'grcmanager'),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Droits : ceux des documents de l'audit
    // ---------------------------------------------------------------------------------------------

    public static function loadAudit(int $auditId): ?Audit
    {
        $audit = new Audit();

        return $auditId > 0 && $audit->getFromDB($auditId) ? $audit : null;
    }

    public static function canViewFor(Audit $audit, ?int $usersId = null): bool
    {
        return !$audit->isNewItem()
            && $audit->can($audit->getID(), READ)
            && AuditTeam::canAccessDocuments($audit->getID(), $usersId);
    }

    /** Droit de modifier l'audit, équipe de l'audit (ou RSSI), sans regarder si l'audit est clos. */
    public static function canManageFor(Audit $audit, ?int $usersId = null): bool
    {
        return !$audit->isNewItem()
            && $audit->can($audit->getID(), UPDATE)
            && AuditTeam::canAccessDocuments($audit->getID(), $usersId);
    }

    public static function canEditFor(Audit $audit, ?int $usersId = null): bool
    {
        return self::canManageFor($audit, $usersId)
            && !Rules::isAuditClosed((string) ($audit->fields['status'] ?? ''));
    }

    // ---------------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public static function getLines(int $auditId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['plugin_grcmanager_audits_id' => $auditId],
            'ORDER' => ['rank', 'id'],
        ]), false));
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

    /**
     * Intitulé d'une ligne : numéro et titre court (traduit pour un article ou une mesure, saisi pour
     * une exigence de référentiel ou un point libre).
     *
     * @param array<string, mixed> $line
     */
    public static function lineTitle(array $line): string
    {
        $code = (string) ($line['code'] ?? '');
        $title = match ((string) ($line['ref_type'] ?? '')) {
            Rules::TYPE_CLAUSE  => IsmsRequirement::getClauseTitle($code),
            Rules::TYPE_CONTROL => Control::getControlTitle($code),
            default             => (string) ($line['label'] ?? ''),
        };
        if ($title === $code) {
            $title = '';
        }

        return trim($code . ($code !== '' && $title !== '' ? ' ' : '') . $title);
    }

    /**
     * Documents de l'audit (onglet Documents), proposés comme preuves examinées.
     *
     * @return array<int, string>
     */
    public static function auditDocuments(int $auditId): array
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
                'WHERE'      => ['di.itemtype' => Audit::class, 'di.items_id' => $auditId],
                'ORDER'      => ['d.name', 'd.id'],
            ]) as $row
        ) {
            $name = (string) ($row['name'] ?: $row['filename']);
            $documents[(int) $row['id']] = $name !== '' ? $name : '#' . (int) $row['id'];
        }

        return $documents;
    }

    /**
     * @return array<int, list<int>> ligne => documents liés
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
                'SELECT' => ['plugin_grcmanager_auditchecklistitems_id', 'documents_id'],
                'FROM'   => self::DOCUMENTS_TABLE,
                'WHERE'  => ['plugin_grcmanager_auditchecklistitems_id' => $lineIds],
                'ORDER'  => ['id'],
            ]) as $row
        ) {
            $links[(int) $row['plugin_grcmanager_auditchecklistitems_id']][] = (int) $row['documents_id'];
        }

        return $links;
    }

    /**
     * Constats de l'audit, proposés pour lier une ligne à une non-conformité existante.
     *
     * @return array<int, string>
     */
    public static function auditFindings(int $auditId): array
    {
        global $DB;

        $findings = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'title'],
                'FROM'   => Nonconformity::getTable(),
                'WHERE'  => ['plugin_grcmanager_audits_id' => $auditId],
                'ORDER'  => ['id'],
            ]) as $row
        ) {
            $findings[(int) $row['id']] = (string) $row['title'];
        }

        return $findings;
    }

    // ---------------------------------------------------------------------------------------------
    // Exigences proposées
    // ---------------------------------------------------------------------------------------------

    /**
     * Exigences proposées pour la grille d'un audit, par clé (ChecklistRules::refKey()) : articles du
     * SMSI (référentiel ISO 27001 activé), mesures applicables de la SoA de l'entité de l'audit,
     * exigences des référentiels personnalisés activés.
     *
     * @return array<string, array{key: string, type: string, items_id: int, code: string, label: string,
     *     group: string}>
     */
    public static function catalog(int $entityId): array
    {
        global $DB;

        $catalog = [];
        if (FrameworkConfig::isEnabled(FrameworkSelection::ISO27001)) {
            $codes = [];
            foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => IsmsRequirement::getTable()]) as $row) {
                $codes[(string) $row['code']] = (int) $row['id'];
            }
            uksort($codes, 'version_compare');
            $group = __('ISMS requirements (clauses 4 to 10)', 'grcmanager');
            foreach ($codes as $code => $id) {
                $code = (string) $code;
                $catalog[Rules::TYPE_CLAUSE . ':' . $code] = [
                    'key'      => Rules::TYPE_CLAUSE . ':' . $code,
                    'type'     => Rules::TYPE_CLAUSE,
                    'items_id' => $id,
                    'code'     => $code,
                    'label'    => IsmsRequirement::getClauseTitle($code),
                    'group'    => $group,
                ];
            }
        }

        $controls = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code'],
                'FROM'   => Control::getTable(),
                'WHERE'  => EntityPerimeter::controlCriteria($entityId) + ['NOT' => ['applicability' => 'no']],
            ]) as $row
        ) {
            $controls[(string) $row['code']] = (int) $row['id'];
        }
        uksort($controls, 'strnatcmp');
        $group = __('Applicable Annex A controls', 'grcmanager');
        foreach ($controls as $code => $id) {
            $code = (string) $code;
            $catalog[Rules::TYPE_CONTROL . ':' . $code] = [
                'key'      => Rules::TYPE_CONTROL . ':' . $code,
                'type'     => Rules::TYPE_CONTROL,
                'items_id' => $id,
                'code'     => $code,
                'label'    => Control::getControlTitle($code),
                'group'    => $group,
            ];
        }

        foreach (self::customRequirements() as $row) {
            $key = Rules::TYPE_CUSTOM . ':' . $row['id'];
            $catalog[$key] = [
                'key'      => $key,
                'type'     => Rules::TYPE_CUSTOM,
                'items_id' => $row['id'],
                'code'     => $row['code'],
                'label'    => $row['title'],
                'group'    => $row['framework'],
            ];
        }

        return $catalog;
    }

    /**
     * Exigences en vigueur des référentiels personnalisés activés.
     *
     * @return list<array{id: int, code: string, title: string, framework: string}>
     */
    private static function customRequirements(): array
    {
        global $DB;

        if (!$DB->tableExists(CustomFramework::getTable())) {
            return [];
        }
        $frameworks = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'version'], 'FROM' => CustomFramework::getTable()]) as $row) {
            if (FrameworkConfig::isEnabled(CustomFrameworkCatalog::key((int) $row['id']))) {
                $frameworks[(int) $row['id']] = trim($row['name'] . ' ' . $row['version']);
            }
        }
        if ($frameworks === []) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'plugin_grcmanager_customframeworks_id', 'code', 'title'],
                'FROM'   => CustomRequirement::getTable(),
                'WHERE'  => ['plugin_grcmanager_customframeworks_id' => array_keys($frameworks), 'is_removed' => 0],
                'ORDER'  => ['plugin_grcmanager_customframeworks_id', 'rank', 'id'],
            ]) as $row
        ) {
            $rows[] = [
                'id'        => (int) $row['id'],
                'code'      => (string) $row['code'],
                'title'     => (string) $row['title'],
                'framework' => $frameworks[(int) $row['plugin_grcmanager_customframeworks_id']],
            ];
        }

        return $rows;
    }

    /**
     * Options groupées pour la liste déroulante (optgroups), sans les exigences déjà dans la grille.
     *
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, true>                 $existing clés déjà présentes
     * @return array<string, array<string, string>>
     */
    public static function catalogOptions(array $catalog, array $existing): array
    {
        $options = [];
        foreach ($catalog as $key => $entry) {
            if (isset($existing[$key])) {
                continue;
            }
            $options[(string) $entry['group']][$key] = trim($entry['code'] . ' ' . $entry['label']);
        }

        return $options;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<string, true>
     */
    public static function existingKeys(array $lines): array
    {
        $keys = [];
        foreach ($lines as $line) {
            $key = Rules::refKey((string) $line['ref_type'], (string) $line['code'], (int) $line['items_id']);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    // ---------------------------------------------------------------------------------------------
    // Écriture (droits vérifiés par l'appelant : canEditFor())
    // ---------------------------------------------------------------------------------------------

    private static function nextRank(int $auditId): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['rank'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['plugin_grcmanager_audits_id' => $auditId],
            'ORDER'  => ['rank DESC'],
            'LIMIT'  => 1,
        ])->current();

        return (int) ($row['rank'] ?? 0) + 1;
    }

    /**
     * Ajoute à la grille les exigences choisies (clés du catalogue), sans doublon.
     *
     * @param list<string>          $keys
     * @param array<string, string> $questions question par clé (modèle), facultatif
     * @return int lignes ajoutées
     */
    public static function addRequirements(Audit $audit, array $keys, array $questions = []): int
    {
        global $DB;

        $auditId = $audit->getID();
        $catalog = self::catalog((int) $audit->fields['entities_id']);
        $existing = self::existingKeys(self::getLines($auditId));
        $rank = self::nextRank($auditId);
        $added = 0;
        $now = date('Y-m-d H:i:s');
        $keys = array_slice(array_values(array_unique(array_map('strval', $keys))), 0, Rules::MAX_LINES_PER_ADD);
        foreach ($keys as $key) {
            $entry = $catalog[$key] ?? null;
            if ($entry === null || isset($existing[$key])) {
                continue;
            }
            $DB->insert(self::TABLE, [
                'plugin_grcmanager_audits_id' => $auditId,
                'ref_type'                    => $entry['type'],
                'items_id'                    => $entry['items_id'],
                'code'                        => mb_substr($entry['code'], 0, Rules::MAX_CODE_LENGTH),
                // Article ou mesure : titre traduit à l'affichage, rien n'est figé dans une langue.
                'label'                       => $entry['type'] === Rules::TYPE_CUSTOM
                    ? mb_substr($entry['label'], 0, Rules::MAX_LABEL_LENGTH) : '',
                'question'                    => Rules::cleanLongText($questions[$key] ?? ''),
                'result'                      => Rules::RESULT_NOT_AUDITED,
                'rank'                        => $rank++,
                'users_id_lastupdater'        => (int) Session::getLoginUserID(),
                'date_creation'               => $now,
                'date_mod'                    => $now,
            ]);
            $existing[$key] = true;
            $added++;
        }

        return $added;
    }

    /** Point libre (exigence interne, processus, site...). */
    public static function addFreeLine(Audit $audit, mixed $code, mixed $label, mixed $question): bool
    {
        global $DB;

        $code = Rules::cleanText($code, Rules::MAX_CODE_LENGTH);
        $label = Rules::cleanText($label, Rules::MAX_LABEL_LENGTH);
        $question = Rules::cleanLongText($question);
        if ($label === '' && $question === '') {
            Session::addMessageAfterRedirect(
                __('Give at least a title or a question for the free item.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }
        $now = date('Y-m-d H:i:s');

        return (bool) $DB->insert(self::TABLE, [
            'plugin_grcmanager_audits_id' => $audit->getID(),
            'ref_type'                    => Rules::TYPE_FREE,
            'items_id'                    => 0,
            'code'                        => $code,
            'label'                       => $label,
            'question'                    => $question,
            'result'                      => Rules::RESULT_NOT_AUDITED,
            'rank'                        => self::nextRank($audit->getID()),
            'users_id_lastupdater'        => (int) Session::getLoginUserID(),
            'date_creation'               => $now,
            'date_mod'                    => $now,
        ]);
    }

    /**
     * Saisie de l'auditeur sur une ligne de cet audit. Les documents ne peuvent être que ceux de
     * l'audit ; le constat lié, qu'une non-conformité de cet audit (0 pour délier).
     *
     * @param array<string, mixed> $input
     */
    public static function updateLine(Audit $audit, int $lineId, array $input): bool
    {
        global $DB;

        $line = self::getLine($lineId);
        if ($line === null || (int) $line['plugin_grcmanager_audits_id'] !== $audit->getID()) {
            return false;
        }
        $auditee = max(0, (int) ($input['users_id_auditee'] ?? 0));
        if ($auditee > 0 && !self::isActiveUser($auditee)) {
            $auditee = 0;
        }
        $finding = max(0, (int) ($input['plugin_grcmanager_nonconformities_id'] ?? 0));
        if ($finding > 0 && !array_key_exists($finding, self::auditFindings($audit->getID()))) {
            $finding = (int) $line['plugin_grcmanager_nonconformities_id'];
        }
        $fields = [
            'question'                             => Rules::cleanLongText($input['question'] ?? ''),
            'users_id_auditee'                     => $auditee,
            'evidence'                             => Rules::cleanLongText($input['evidence'] ?? ''),
            'result'                               => Rules::normalizeResult((string) ($input['result'] ?? '')),
            'comment'                              => Rules::cleanLongText($input['comment'] ?? ''),
            'plugin_grcmanager_nonconformities_id' => $finding,
            'users_id_lastupdater'                 => (int) Session::getLoginUserID(),
            'date_mod'                             => date('Y-m-d H:i:s'),
        ];
        if ((string) $line['ref_type'] === Rules::TYPE_FREE) {
            $fields['code'] = Rules::cleanText($input['code'] ?? $line['code'], Rules::MAX_CODE_LENGTH);
            $fields['label'] = Rules::cleanText($input['label'] ?? $line['label'], Rules::MAX_LABEL_LENGTH);
        }
        $DB->update(self::TABLE, $fields, ['id' => $lineId]);

        $documents = array_intersect(
            array_unique(array_map('intval', (array) ($input['documents'] ?? []))),
            array_keys(self::auditDocuments($audit->getID()))
        );
        $DB->delete(self::DOCUMENTS_TABLE, ['plugin_grcmanager_auditchecklistitems_id' => $lineId]);
        foreach ($documents as $documentsId) {
            $DB->insert(self::DOCUMENTS_TABLE, [
                'plugin_grcmanager_auditchecklistitems_id' => $lineId,
                'documents_id'                             => $documentsId,
                'date_creation'                            => date('Y-m-d H:i:s'),
            ]);
        }

        return true;
    }

    public static function deleteLine(Audit $audit, int $lineId): bool
    {
        global $DB;

        $line = self::getLine($lineId);
        if ($line === null || (int) $line['plugin_grcmanager_audits_id'] !== $audit->getID()) {
            return false;
        }
        // Le constat éventuellement créé depuis la ligne reste au registre des non-conformités.
        $DB->delete(self::DOCUMENTS_TABLE, ['plugin_grcmanager_auditchecklistitems_id' => $lineId]);

        return (bool) $DB->delete(self::TABLE, ['id' => $lineId]);
    }

    private static function userName(int $usersId): string
    {
        return $usersId > 0 ? (string) getUserName($usersId) : '';
    }

    public static function addedMessage(int $count): string
    {
        return sprintf(
            _n('%d line added to the checklist.', '%d lines added to the checklist.', $count, 'grcmanager'),
            $count
        );
    }

    private static function isActiveUser(int $usersId): bool
    {
        $user = new User();

        return $user->getFromDB($usersId) && (int) $user->fields['is_active'] === 1
            && (int) $user->fields['is_deleted'] === 0;
    }

    // ---------------------------------------------------------------------------------------------
    // Constat créé depuis une ligne
    // ---------------------------------------------------------------------------------------------

    /**
     * Valeurs proposées pour le constat d'une ligne (fiche de non-conformité pré-remplie, relue et
     * enregistrée par l'utilisateur). Null si la ligne n'appelle pas de constat, en a déjà un, ou si
     * l'utilisateur ne peut pas modifier la grille.
     *
     * @return array<string, mixed>|null
     */
    public static function findingDraft(int $lineId): ?array
    {
        $line = self::getLine($lineId);
        if ($line === null || (int) $line['plugin_grcmanager_nonconformities_id'] > 0) {
            return null;
        }
        $preset = Rules::findingPreset((string) $line['result']);
        $audit = self::loadAudit((int) $line['plugin_grcmanager_audits_id']);
        if ($preset === null || $audit === null || !self::canEditFor($audit)) {
            return null;
        }
        $title = self::lineTitle($line);
        $resultLabel = self::getResultLabels()[(string) $line['result']] ?? '';
        $parts = [];
        foreach (
            [
                __('Audited requirement', 'grcmanager') => $title,
                __('Question asked', 'grcmanager')      => (string) $line['question'],
                __('Evidence examined', 'grcmanager')   => (string) $line['evidence'],
                __('Auditor comment', 'grcmanager')     => (string) $line['comment'],
            ] as $heading => $text
        ) {
            if (trim($text) !== '') {
                $parts[] = $heading . "\n" . trim($text);
            }
        }

        return $preset + [
            'line_id'                     => $lineId,
            'line_title'                  => $title,
            'result_label'                => $resultLabel,
            'plugin_grcmanager_audits_id' => $audit->getID(),
            'title'                       => mb_substr(
                sprintf(__('%1$s: %2$s', 'grcmanager'), $resultLabel, $title),
                0,
                Rules::MAX_LABEL_LENGTH
            ),
            'description'                 => implode("\n\n", $parts),
        ];
    }

    /**
     * Après l'enregistrement par l'utilisateur d'un constat ouvert depuis une ligne : la ligne est
     * liée à ce constat s'il est de son audit et si la ligne n'en a pas déjà un.
     */
    public static function attachFinding(int $lineId, Nonconformity $finding): bool
    {
        global $DB;

        $line = self::getLine($lineId);
        $audit = $line === null ? null : self::loadAudit((int) $line['plugin_grcmanager_audits_id']);
        if (
            $line === null || $audit === null
            || (int) $line['plugin_grcmanager_nonconformities_id'] > 0
            || (int) ($finding->fields['plugin_grcmanager_audits_id'] ?? 0) !== $audit->getID()
            || !self::canEditFor($audit)
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
     * Lignes de grille liées à un constat (fiche de la non-conformité), si l'utilisateur peut lire
     * la grille de leur audit.
     *
     * @return list<array{title: string, result: string, result_class: string, result_icon: string,
     *     audit: string, url: string}>
     */
    public static function linesForFinding(int $findingId): array
    {
        global $DB;

        $lines = [];
        if ($findingId <= 0) {
            return $lines;
        }
        $results = self::getResults();
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_nonconformities_id' => $findingId],
                'ORDER' => ['id'],
            ]) as $line
        ) {
            $audit = self::loadAudit((int) $line['plugin_grcmanager_audits_id']);
            if ($audit === null || !self::canViewFor($audit)) {
                continue;
            }
            $result = $results[(string) $line['result']] ?? $results[Rules::RESULT_NOT_AUDITED];
            $lines[] = [
                'title'        => self::lineTitle($line),
                'result'       => $result[0],
                'result_class' => $result[1],
                'result_icon'  => $result[2],
                'audit'        => (string) $audit->fields['title'],
                'url'          => self::getAuditTabURL($audit->getID()) . '#grc-checklist-line-' . (int) $line['id'],
            ];
        }

        return $lines;
    }

    /** Constat purgé : les lignes restent, sans lien. */
    public static function unlinkFinding(int $findingId): void
    {
        global $DB;

        $DB->update(self::TABLE, ['plugin_grcmanager_nonconformities_id' => 0], [
            'plugin_grcmanager_nonconformities_id' => $findingId,
        ]);
    }

    /** Audit purgé : sa grille disparaît avec lui. */
    public static function purgeAudit(int $auditId): void
    {
        global $DB;

        $ids = array_column(self::getLines($auditId), 'id');
        if ($ids !== []) {
            $DB->delete(self::DOCUMENTS_TABLE, ['plugin_grcmanager_auditchecklistitems_id' => $ids]);
            $DB->delete(self::TABLE, ['id' => $ids]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Affichage
    // ---------------------------------------------------------------------------------------------

    /**
     * Lignes prêtes pour l'onglet et le rapport PDF.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(int $auditId): array
    {
        $lines = self::getLines($auditId);
        $documents = self::auditDocuments($auditId);
        $links = self::lineDocuments(array_map(static fn (array $l): int => (int) $l['id'], $lines));
        $findings = self::auditFindings($auditId);
        $results = self::getResults();
        $types = self::getRefTypes();
        $rows = [];
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $resultKey = Rules::normalizeResult((string) $line['result']);
            $result = $results[$resultKey];
            $findingId = (int) $line['plugin_grcmanager_nonconformities_id'];
            $lineDocs = [];
            foreach ($links[$id] ?? [] as $documentsId) {
                if (isset($documents[$documentsId])) {
                    $lineDocs[] = [
                        'id'   => $documentsId,
                        'name' => $documents[$documentsId],
                        'url'  => Document::getFormURLWithID($documentsId),
                    ];
                }
            }
            $rows[] = [
                'id'            => $id,
                'type'          => (string) $line['ref_type'],
                'type_label'    => $types[(string) $line['ref_type']] ?? '',
                'code'          => (string) $line['code'],
                'label'         => (string) $line['label'],
                'title'         => self::lineTitle($line),
                'question'      => (string) $line['question'],
                'auditee_id'    => (int) $line['users_id_auditee'],
                'auditee'       => self::userName((int) $line['users_id_auditee']),
                'evidence'      => (string) $line['evidence'],
                'documents'     => $lineDocs,
                'document_ids'  => array_column($lineDocs, 'id'),
                'result'        => $resultKey,
                'result_label'  => $result[0],
                'result_class'  => $result[1],
                'result_icon'   => $result[2],
                'comment'       => (string) $line['comment'],
                'finding_id'    => isset($findings[$findingId]) ? $findingId : 0,
                'finding_title' => $findings[$findingId] ?? '',
                'finding_url'   => isset($findings[$findingId]) ? Nonconformity::getFormURLWithID($findingId) : '',
                'can_raise'     => Rules::canRaiseFinding($resultKey) && !isset($findings[$findingId]),
                'updated'       => Html::convDateTime($line['date_mod']),
                'updater'       => self::userName((int) $line['users_id_lastupdater']),
            ];
        }

        return $rows;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !Audit::canView()) {
            return '';
        }

        return self::createTabEntry(
            __('Audit checklist', 'grcmanager'),
            AuditTeam::canAccessDocuments($item->getID()) ? count(self::getLines($item->getID())) : 0,
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Audit || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForAudit($item);

        return true;
    }

    public static function showForAudit(Audit $audit): void
    {
        $auditId = $audit->getID();
        if (!self::canViewFor($audit)) {
            TemplateRenderer::getInstance()->display('@grcmanager/audit_checklist_tab.html.twig', [
                'allowed' => false,
            ]);

            return;
        }
        $rows = self::present($auditId);
        $canEdit = self::canEditFor($audit);
        $entityId = (int) $audit->fields['entities_id'];
        $catalog = $canEdit ? self::catalog($entityId) : [];
        $existing = self::existingKeys(self::getLines($auditId));
        // Mesures du périmètre de l'audit pas encore dans la grille : présélectionnées.
        $scope = [];
        foreach (array_keys(Audit::getLinkedControls($auditId)) as $controlId) {
            foreach ($catalog as $key => $entry) {
                if (
                    $entry['type'] === Rules::TYPE_CONTROL && $entry['items_id'] === $controlId
                    && !isset($existing[$key])
                ) {
                    $scope[] = $key;
                }
            }
        }

        TemplateRenderer::getInstance()->display('@grcmanager/audit_checklist_tab.html.twig', [
            'allowed'          => true,
            'audit_id'         => $auditId,
            'rows'             => $rows,
            'summary'          => Rules::summary(array_column($rows, 'result')),
            'results'          => self::getResults(),
            'result_labels'    => self::getResultLabels(),
            'can_edit'         => $canEdit,
            'is_closed'        => Rules::isAuditClosed((string) $audit->fields['status']),
            'can_create_finding' => $canEdit && Nonconformity::canCreate(),
            'finding_url'      => Nonconformity::getFormURL(),
            'action'           => self::getFormURL(),
            'pdf_url'          => self::getPdfURL($auditId),
            'coverage_url'     => self::getCoverageURL(),
            'catalog_options'  => self::catalogOptions($catalog, $existing),
            'scope_keys'       => $scope,
            'documents'        => self::auditDocuments($auditId),
            'findings'         => [0 => '-----'] + self::auditFindings($auditId),
            'user_entity'      => $entityId,
            'user_entity_sons' => (bool) $audit->fields['is_recursive'],
            'templates'        => AuditChecklistTemplate::available(),
            'can_delete_templates' => Session::haveRight(Audit::RIGHTNAME, UPDATE),
        ]);
    }

    /**
     * Lignes du rapport PDF de l'audit.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function reportData(int $auditId): array
    {
        $rows = self::present($auditId);

        return ['rows' => $rows, 'summary' => Rules::summary(array_column($rows, 'result'))];
    }

    // ---------------------------------------------------------------------------------------------
    // Couverture du programme d'audit
    // ---------------------------------------------------------------------------------------------

    /**
     * Couverture sur les $years dernières années, pour les exigences du SMSI et les mesures
     * applicables de la SoA de l'entité active, d'après les grilles des audits des entités de la
     * session (audits annulés exclus ; date réelle, sinon date prévue). Issue #773 : $since impose
     * le début de la période (premier jour du cycle du programme d'audit).
     *
     * @return array<string, mixed>
     */
    public static function coverage(int $years, ?string $today = null, ?string $since = null): array
    {
        global $DB;

        $years = Rules::coverageYears($years);
        $today ??= date('Y-m-d');
        $since ??= Rules::coverageStart($today, $years);
        $catalog = [];
        foreach (self::catalog(EntityPerimeter::activeEntity()) as $entry) {
            if ($entry['type'] !== Rules::TYPE_CUSTOM) {
                $catalog[] = [
                    'key'   => $entry['key'],
                    'type'  => $entry['type'],
                    'code'  => $entry['code'],
                    'label' => $entry['label'],
                ];
            }
        }

        $lines = [];
        $items = self::TABLE;
        $audits = Audit::getTable();
        foreach (
            $DB->request([
                'SELECT'     => [
                    "$items.ref_type", "$items.code", "$items.items_id", "$items.result",
                    "$audits.id AS audit_id", "$audits.title AS audit_title", "$audits.actual_date",
                    "$audits.planned_date",
                ],
                'FROM'       => $items,
                'INNER JOIN' => [
                    $audits => ['FKEY' => [$items => 'plugin_grcmanager_audits_id', $audits => 'id']],
                ],
                'WHERE'      => array_merge(
                    [
                        'NOT'            => ["$audits.status" => 'cancelled'],
                        "$items.ref_type" => [Rules::TYPE_CLAUSE, Rules::TYPE_CONTROL],
                    ],
                    EntityPerimeter::criteria($audits)
                ),
            ]) as $row
        ) {
            $lines[] = [
                'key'         => Rules::refKey(
                    (string) $row['ref_type'],
                    (string) $row['code'],
                    (int) $row['items_id']
                ),
                'date'        => (string) ($row['actual_date'] ?: $row['planned_date']),
                'result'      => (string) $row['result'],
                'audit_id'    => (int) $row['audit_id'],
                'audit_title' => (string) $row['audit_title'],
            ];
        }

        $coverage = Rules::coverage($catalog, $lines, $since);
        $results = self::getResults();
        $types = self::getRefTypes();
        foreach ($coverage['rows'] as &$row) {
            $result = $row['last_result'] !== null ? ($results[$row['last_result']] ?? null) : null;
            $row['type_label'] = $types[$row['type']] ?? '';
            $row['last_date_text'] = $row['last_date'] !== null ? Html::convDate($row['last_date']) : '';
            $row['result_label'] = $result[0] ?? '';
            $row['result_class'] = $result[1] ?? '';
            $row['result_icon'] = $result[2] ?? '';
            $row['audit_url'] = $row['audit_id'] > 0 ? Audit::getFormURLWithID((int) $row['audit_id']) : '';
        }
        unset($row);

        return $coverage + ['years' => $years, 'since' => Html::convDate($since)];
    }
}
