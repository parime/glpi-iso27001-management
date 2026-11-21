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

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\AdAttackPathTables as Tables;
use GlpiPlugin\Grcmanager\Services\Access\AdAuditRules;
use GlpiPlugin\Grcmanager\Services\Access\AttackPathReport as Report;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use Html;
use Session;

/**
 * Issue #835, lot 3 : chemins d'attaque de l'Active Directory, sur la page de l'audit de sécurité de
 * l'AD (AdSecurityAudit), même droit (registre sensible) et mêmes entités.
 *
 * Le plugin ne lance ni BloodHound CE ni Adalanche et n'interroge pas l'annuaire : il lit l'export
 * JSON du graphe (AttackPathReport) et garde, par import, le nombre de comptes ayant un chemin vers
 * Domain Admins et les comptes les plus exposés (nom, type, sauts). Un nouvel import du même domaine
 * montre l'évolution. Sur clic seulement, le risque est créé dans le registre (une fois, repris par
 * les imports suivants du domaine). Preuve sur les mesures A.5.15, A.5.18 et A.8.2, source de KRI.
 */
final class AdAttackPath
{
    public const FK = 'plugin_grcmanager_adattackpaths_id';

    public const MAX_HISTORY = 100;

    /** Mesures dont la fiche montre la preuve. */
    public const EVIDENCE_CONTROLS = ['A.5.15', 'A.5.18', 'A.8.2'];

    public static function canView(): bool
    {
        return AdSecurityAudit::canView();
    }

    public static function canManage(): bool
    {
        return AdSecurityAudit::canManage();
    }

    /**
     * @return array<string, array{label: string, icon: string, color: string}>
     */
    public static function toolInfos(): array
    {
        return [
            Report::TOOL_BLOODHOUND => ['label' => 'BloodHound CE', 'icon' => 'ti ti-dog', 'color' => 'red'],
            Report::TOOL_ADALANCHE  => ['label' => 'Adalanche', 'icon' => 'ti ti-route', 'color' => 'teal'],
        ];
    }

    public static function importErrorMessage(string $code): string
    {
        return match ($code) {
            Report::ERROR_EMPTY     => __('The file is empty.', 'grcmanager'),
            Report::ERROR_TOO_LARGE => __('The file is too large (20 MB at most).', 'grcmanager'),
            Report::ERROR_NOT_GRAPH => __(
                'This file is not a graph exported from BloodHound CE or Adalanche (JSON with nodes and edges).',
                'grcmanager'
            ),
            Report::ERROR_NO_TARGET => __(
                'The graph contains no Domain Admins group: export the paths that lead to this group.',
                'grcmanager'
            ),
            'no_domain'             => __(
                'The export gives no domain name: fill in the domain field of the import form.',
                'grcmanager'
            ),
            'duplicate'             => __('This report has already been imported.', 'grcmanager'),
            'rights'                => __('You do not have the right to import a report.', 'grcmanager'),
            default                 => __('The file could not be read.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * @return array<string, mixed>|null
     */
    public static function getImport(int $id): ?array
    {
        global $DB;

        if ($id <= 0 || !self::canView()) {
            return null;
        }
        $row = $DB->request(['FROM' => Tables::IMPORTS, 'WHERE' => ['id' => $id]])->current();
        if ($row === null || !Session::haveAccessToEntity((int) $row['entities_id'])) {
            return null;
        }

        return $row;
    }

    /**
     * Imports des entités de la session, les plus récents d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function imports(): array
    {
        return self::canView() ? self::fetch(getEntitiesRestrictCriteria(Tables::IMPORTS)) : [];
    }

    /**
     * @param array<mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function fetch(array $where): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::IMPORTS,
                'WHERE' => $where,
                'ORDER' => ['date_import DESC', 'id DESC'],
                'LIMIT' => self::MAX_HISTORY,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Dernier import de chaque domaine (par entité et outil).
     *
     * @param list<array<string, mixed>> $imports du plus récent au plus ancien
     * @return list<array<string, mixed>>
     */
    public static function latestByDomain(array $imports): array
    {
        $latest = [];
        foreach ($imports as $import) {
            $latest[$import['entities_id'] . '|' . $import['tool'] . '|' . $import['domain']] ??= $import;
        }

        return array_values($latest);
    }

    /**
     * @param array<string, mixed> $import
     * @return array<string, mixed>|null
     */
    public static function previousImport(array $import): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => Tables::IMPORTS,
            'WHERE' => [
                'entities_id' => (int) $import['entities_id'],
                'tool'        => (string) $import['tool'],
                'domain'      => (string) $import['domain'],
                'id'          => ['<', (int) $import['id']],
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();

        return $row ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function accountsOf(int $importId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::ACCOUNTS,
                'WHERE' => [self::FK => $importId],
                'ORDER' => ['hops ASC', 'routes DESC', 'name ASC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * KRI : plus grand nombre de comptes ayant un chemin vers Domain Admins, sur le dernier import de
     * chaque domaine d'un périmètre d'entités ; null sans import.
     *
     * @param list<int> $perimeter
     */
    public static function worstPathCount(array $perimeter): ?float
    {
        if ($perimeter === []) {
            return null;
        }
        $latest = self::latestByDomain(self::fetch(['entities_id' => $perimeter]));

        return $latest === []
            ? null
            : (float) max(array_map(static fn (array $i): int => (int) $i['path_count'], $latest));
    }

    // ------------------------------------------------------------------ Import, suppression.

    /**
     * Importe un export BloodHound CE ou Adalanche dans l'entité active. Le même fichier n'est importé
     * qu'une fois par entité ; le risque lié à l'import précédent du domaine est repris.
     *
     * @return array{id: int, error: string, paths: int, truncated: bool}
     */
    public static function import(string $raw, string $fileName, string $domainHint = ''): array
    {
        global $DB;

        $result = ['id' => 0, 'error' => '', 'paths' => 0, 'truncated' => false];
        if (!self::canManage()) {
            return ['error' => 'rights'] + $result;
        }
        $report = Report::parse($raw);
        if ($report['error'] !== '') {
            return ['error' => $report['error']] + $result;
        }
        $domain = $report['domain'] !== '' ? $report['domain'] : AdAuditRules::normalizeDomain($domainHint);
        if ($domain === '') {
            return ['error' => 'no_domain'] + $result;
        }
        $entity = (int) Session::getActiveEntity();
        $hash = hash('sha256', $raw);
        if (countElementsInTable(Tables::IMPORTS, ['entities_id' => $entity, 'file_sha256' => $hash]) > 0) {
            return ['error' => 'duplicate'] + $result;
        }
        $previous = self::fetch([
            'entities_id' => $entity, 'tool' => $report['tool'], 'domain' => $domain,
        ])[0] ?? null;

        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $DB->insert(Tables::IMPORTS, [
            'entities_id'                => $entity,
            'tool'                       => $report['tool'],
            'domain'                     => $domain,
            'path_count'                 => $report['paths'],
            'user_count'                 => $report['users'],
            'computer_count'             => $report['computers'],
            'shortest_path'              => $report['shortest'],
            'node_count'                 => $report['nodes'],
            'edge_count'                 => $report['edges'],
            'truncated'                  => $report['truncated'] ? 1 : 0,
            'plugin_grcmanager_risks_id' => (int) ($previous['plugin_grcmanager_risks_id'] ?? 0),
            'file_name'                  => mb_substr(basename($fileName), 0, 255),
            'file_sha256'                => $hash,
            'users_id'                   => (int) Session::getLoginUserID(),
            'date_import'                => $now,
        ]);
        $id = (int) $DB->insertId();
        if ($id <= 0) {
            return ['error' => 'invalid'] + $result;
        }
        foreach ($report['accounts'] as $account) {
            $DB->insert(Tables::ACCOUNTS, [self::FK => $id] + $account);
        }
        AuditLog::record('import', self::class, $id, 0, 'success', [
            'tool' => $report['tool'], 'domain' => $domain, 'paths' => $report['paths'],
        ]);

        return ['id' => $id, 'error' => '', 'paths' => $report['paths'], 'truncated' => $report['truncated']];
    }

    /**
     * Supprime un import et ses comptes ; le risque du registre reste.
     */
    public static function clear(int $id): bool
    {
        global $DB;

        $import = self::canManage() ? self::getImport($id) : null;
        if ($import === null) {
            return false;
        }
        $DB->delete(Tables::ACCOUNTS, [self::FK => $id]);
        $DB->delete(Tables::IMPORTS, ['id' => $id]);
        AuditLog::record('purge', self::class, $id, 0, 'success', ['domain' => (string) $import['domain']]);

        return true;
    }

    // ------------------------------------------------------------------ Registre des risques.

    /**
     * Crée, sur clic, le risque « chemins vers Domain Admins » du domaine dans le registre, une seule
     * fois (le lien est repris par les imports suivants). Renvoie l'identifiant du risque, 0 sinon.
     */
    public static function sendToRegister(int $id): int
    {
        global $DB;

        $import = self::canManage() && Risk::canCreate() ? self::getImport($id) : null;
        if ($import === null || (int) $import['path_count'] <= 0) {
            return 0;
        }
        $linked = (int) $import['plugin_grcmanager_risks_id'];
        if ($linked > 0 && (new Risk())->getFromDB($linked)) {
            return $linked;
        }
        $size = RiskMatrixConfig::size();
        $paths = (int) $import['path_count'];
        $riskId = (new Risk())->add([
            'entities_id' => (int) $import['entities_id'],
            'title'       => sprintf(
                __('Attack paths to Domain Admins: %s', 'grcmanager'),
                (string) $import['domain']
            ),
            'description' => sprintf(
                _n(
                    '%1$s: %2$d account has a path to the Domain Admins group (shortest path: %3$d step). '
                        . 'Details on the Active Directory security audit page.',
                    '%1$s: %2$d accounts have a path to the Domain Admins group (shortest path: %3$d steps). '
                        . 'Details on the Active Directory security audit page.',
                    $paths,
                    'grcmanager'
                ),
                self::toolInfos()[(string) $import['tool']]['label'] ?? (string) $import['tool'],
                $paths,
                (int) $import['shortest_path']
            ),
            'category'    => 'technical',
            'probability' => $paths >= 10 || (int) $import['shortest_path'] <= 1 ? $size : max(1, $size - 1),
            'impact'      => $size,
            'users_id'    => (int) Session::getLoginUserID(),
        ]);
        if ($riskId === false || (int) $riskId <= 0) {
            return 0;
        }
        // Le risque vaut pour le domaine : noté sur tous ses imports de l'entité.
        $DB->update(Tables::IMPORTS, ['plugin_grcmanager_risks_id' => (int) $riskId], [
            'entities_id' => (int) $import['entities_id'],
            'tool'        => (string) $import['tool'],
            'domain'      => (string) $import['domain'],
        ]);

        return (int) $riskId;
    }

    // ------------------------------------------------------------------ Affichage.

    /**
     * @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    public static function present(array $import, bool $withAccounts = false): array
    {
        $previous = self::previousImport($import);
        $tool = (string) $import['tool'];
        $paths = (int) $import['path_count'];
        $riskId = (int) $import['plugin_grcmanager_risks_id'];
        $risk = new Risk();
        $hasRisk = $riskId > 0 && $risk->getFromDB($riskId) && $risk->canViewItem();
        $data = [
            'id'        => (int) $import['id'],
            'domain'    => (string) $import['domain'],
            'tool'      => $tool,
            'tool_info' => self::toolInfos()[$tool] ?? self::toolInfos()[Report::TOOL_BLOODHOUND],
            'paths'     => $paths,
            'users'     => (int) $import['user_count'],
            'computers' => (int) $import['computer_count'],
            'shortest'  => $import['shortest_path'] === null ? null : (int) $import['shortest_path'],
            'trend'     => $previous === null ? null : $paths - (int) $previous['path_count'],
            'truncated' => (bool) $import['truncated'],
            'date'      => (string) Html::convDateTime((string) $import['date_import']),
            'file'      => (string) $import['file_name'],
            'entity'    => (string) Dropdown::getDropdownName('glpi_entities', (int) $import['entities_id']),
            'risk_url'  => $hasRisk ? Risk::getFormURLWithID($riskId) : '',
            'risk_name' => $hasRisk ? (string) $risk->fields['title'] : '',
            'url'       => AdSecurityAudit::getSearchURL() . '?path=' . (int) $import['id'] . '#grc-ad-paths',
        ];
        if ($withAccounts) {
            $before = [];
            foreach ($previous === null ? [] : self::accountsOf((int) $previous['id']) as $row) {
                $before[(string) $row['name']] = (int) $row['hops'];
            }
            $data['accounts'] = array_map(static fn (array $row): array => [
                'name'   => (string) $row['name'],
                'kind'   => (string) $row['kind'],
                'hops'   => (int) $row['hops'],
                'routes' => (int) $row['routes'],
                'new'    => $previous !== null && !isset($before[(string) $row['name']]),
            ], self::accountsOf((int) $import['id']));
            $data['has_previous'] = $previous !== null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function sectionData(int $selectedId = 0): array
    {
        $imports = self::imports();
        $selected = null;
        foreach ($imports as $import) {
            if ((int) $import['id'] === $selectedId) {
                $selected = $import;
            }
        }
        $selected ??= $imports[0] ?? null;

        return [
            'path_domains'    => array_map(
                static fn (array $i): array => self::present($i),
                self::latestByDomain($imports)
            ),
            'path_history'    => array_map(static fn (array $i): array => self::present($i), $imports),
            'path_detail'     => $selected === null ? null : self::present($selected, true),
            'path_tools'      => self::toolInfos(),
            'can_manage'      => self::canManage(),
            'can_risk'        => self::canManage() && Risk::canCreate(),
            'action'          => AdSecurityAudit::getSearchURL(),
            'example_base'    => AdSecurityAudit::getSearchURL() . '?example=',
            'max_mb'          => (int) (Report::MAX_BYTES / 1024 / 1024),
            'max_accounts'    => Report::MAX_ACCOUNTS,
        ];
    }

    public static function showSection(int $selectedId = 0): void
    {
        if (!self::canView()) {
            return;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/_ad_attack_paths.html.twig',
            self::sectionData($selectedId)
        );
    }

    // ------------------------------------------------------------------ Preuve.

    /**
     * Preuve des mesures A.5.15, A.5.18 et A.8.2 : dernier import de chaque domaine.
     *
     * @return array{domains: list<array<string, mixed>>, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::EVIDENCE_CONTROLS, true) || !self::canView()) {
            return null;
        }
        $domains = array_map(
            static fn (array $i): array => self::present($i),
            self::latestByDomain(self::imports())
        );

        return $domains === []
            ? null
            : ['domains' => $domains, 'url' => AdSecurityAudit::getSearchURL() . '#grc-ad-paths'];
    }
}
