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

use CommonDBTM;
use CommonGLPI;
use Computer;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\ScannerImportTables as Tables;
use GlpiPlugin\Grcmanager\Services\Scanner\ScannerReports as Reports;
use Html;
use Item_Ticket;
use Session;
use Ticket;

/**
 * Issue #543 : import des rapports de scanners de vulnérabilités réseau (mesure A.8.8), page de
 * Parc > Sécurité du parc, groupe Vulnérabilités. Premier format : Greenbone / OpenVAS (XML,
 * voir Services\Scanner\OpenVasReport pour les sources officielles) ; les formats du commerce
 * (Nessus, Qualys, Rapid7) relèvent de l'issue #841.
 *
 * - Chaque constat est rattaché à un ordinateur GLPI visible : rattachement déjà choisi pour cet
 *   hôte, sinon nom d'hôte exact ou nom court, sinon adresse IP portée par un seul ordinateur. Les
 *   hôtes non rapprochés sont listés et se rattachent à la main.
 * - Un constat est unique par outil, hôte, port et test dans l'entité : rejouer un rapport ne crée
 *   aucun doublon, et un constat absent d'une analyse plus récente du même hôte passe « corrigé ».
 * - Historique par import (nombre de constats par gravité), KRI, preuve de la mesure A.8.8, source
 *   de l'onglet Sécurité 360° de l'ordinateur. Ticket proposé, créé seulement sur un clic.
 *
 * Contenu des rapports non fiable : il n'est affiché que par Twig (échappement automatique) et le
 * fichier lui-même n'est pas conservé. Droits : droit du plugin et lecture native des ordinateurs,
 * imports des entités de la session ; modification du plugin pour importer, rattacher et supprimer.
 */
class ScannerImport extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Imports listés au plus dans l'historique. */
    public const MAX_HISTORY = 100;

    /** Constats lus au plus pour la page. */
    public const MAX_ROWS = 500;

    /** Mesures dont la page est une preuve. */
    public const EVIDENCE_CONTROLS = ['A.8.8'];

    public static function getTypeName($nb = 0)
    {
        return __('Vulnerability scanner reports', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-radar-2';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/scannerimport.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Computer::canView();
    }

    /**
     * Import, rattachement, suppression d'un import et ticket proposé.
     */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

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

    public static function maxUploadBytes(): int
    {
        return min(Reports::MAX_BYTES, ConfigurationCompliance::maxUploadBytes());
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function severityLabels(): array
    {
        return [
            Reports::SEVERITY_CRITICAL => ['label' => __('Critical', 'grcmanager'), 'color' => 'danger'],
            Reports::SEVERITY_HIGH     => ['label' => __('High', 'grcmanager'), 'color' => 'warning'],
            Reports::SEVERITY_MEDIUM   => ['label' => __('Medium', 'grcmanager'), 'color' => 'info'],
            Reports::SEVERITY_LOW      => ['label' => __('Low', 'grcmanager'), 'color' => 'secondary'],
        ];
    }

    public static function errorMessage(string $error): string
    {
        return match ($error) {
            Reports::ERROR_TOO_LARGE      => __('The file is too large.', 'grcmanager'),
            Reports::ERROR_DOCTYPE        => __(
                'The file declares a DTD or entities: it is refused for security reasons.',
                'grcmanager'
            ),
            Reports::ERROR_UNKNOWN_FORMAT => __(
                'Format not recognised: export the report from Greenbone in the XML format.',
                'grcmanager'
            ),
            'rights'                      => __('You do not have the right to import.', 'grcmanager'),
            default                       => __('The file could not be read as a scanner report.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Import.

    /**
     * Importe un rapport déjà reçu par le serveur, dans l'entité active.
     *
     * @return array{error: string, scanner: string, findings: int, hosts: int, unmatched: int,
     *     resolved: int, replayed: bool, truncated: bool, skipped: int, id: int}
     */
    public static function importFile(string $path, string $fileName): array
    {
        global $DB;

        $stats = [
            'error' => '', 'scanner' => '', 'findings' => 0, 'hosts' => 0, 'unmatched' => 0, 'resolved' => 0,
            'replayed' => false, 'truncated' => false, 'skipped' => 0, 'id' => 0,
        ];
        if (!self::canManage()) {
            return ['error' => 'rights'] + $stats;
        }
        $parsed = Reports::parseFile($path);
        if ($parsed['error'] !== Reports::ERROR_NONE) {
            return ['error' => $parsed['error']] + $stats;
        }
        if ($parsed['findings'] === [] && $parsed['hosts'] === []) {
            return ['error' => Reports::ERROR_INVALID] + $stats;
        }
        $scanner = $parsed['scanner'];
        $entity = (int) Session::getActiveEntity();
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $sha = (string) hash_file('sha256', $path);
        $uuid = (string) ($parsed['report']['uuid'] ?? '');
        $uuid = $uuid !== '' ? $uuid : $sha;
        $scanDate = (string) ($parsed['report']['scan_start'] ?: ($parsed['report']['scan_end'] ?: $now));

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Tables::IMPORTS,
            'WHERE'  => ['entities_id' => $entity, 'scanner' => $scanner, 'report_uuid' => $uuid],
        ])->current();
        $importId = (int) ($existing['id'] ?? 0);
        $stats['replayed'] = $importId > 0;
        $row = [
            'task_name'   => (string) ($parsed['report']['task'] ?? ''),
            'scan_start'  => $scanDate,
            'scan_end'    => ($parsed['report']['scan_end'] ?? '') !== '' ? $parsed['report']['scan_end'] : null,
            'file_name'   => mb_substr(basename($fileName), 0, 255),
            'file_sha256' => $sha,
            'users_id'    => (int) Session::getLoginUserID(),
            'date_import' => $now,
        ];
        if ($importId > 0) {
            $DB->update(Tables::IMPORTS, $row, ['id' => $importId]);
        } else {
            $DB->insert(Tables::IMPORTS, $row + [
                'entities_id' => $entity, 'scanner' => $scanner, 'report_uuid' => $uuid,
            ]);
            $importId = (int) $DB->insertId();
        }

        // Ordinateur de chaque hôte analysé, cherché une seule fois.
        $computers = [];
        foreach ($parsed['hosts'] as $host => $hostname) {
            $computers[(string) $host] = self::matchHost((string) $host, (string) $hostname, $entity);
        }
        $seenKeys = [];
        foreach ($parsed['findings'] as $finding) {
            $key = Reports::findingKey($scanner, $finding['host'], $finding['port'], $finding['test_id']);
            $seenKeys[$finding['host']][] = $key;
            $computerId = $computers[$finding['host']] ?? 0;
            self::storeFinding($finding, $key, $scanner, $entity, $importId, $scanDate, $computerId);
        }

        // Constat absent d'une analyse plus récente du même hôte : corrigé.
        $resolved = 0;
        foreach (array_keys($parsed['hosts']) as $host) {
            $where = [
                'entities_id' => $entity,
                'scanner'     => $scanner,
                'host'        => (string) $host,
                'is_resolved' => 0,
                'last_seen'   => ['<', $scanDate],
            ];
            if (($seenKeys[$host] ?? []) !== []) {
                $where[] = ['NOT' => ['finding_key' => $seenKeys[$host]]];
            }
            $ids = array_column(iterator_to_array($DB->request([
                'SELECT' => ['id'], 'FROM' => Tables::FINDINGS, 'WHERE' => $where,
            ]), false), 'id');
            if ($ids !== []) {
                $DB->update(Tables::FINDINGS, ['is_resolved' => 1, 'date_resolved' => $scanDate], ['id' => $ids]);
                $resolved += count($ids);
            }
        }

        $counts = Reports::countBySeverity($parsed['findings']);
        $unmatched = count(array_filter($computers, static fn (int $id): bool => $id === 0));
        $DB->update(Tables::IMPORTS, [
            'host_count'      => count($parsed['hosts']),
            'unmatched_count' => $unmatched,
            'finding_count'   => $counts['total'],
            'critical_count'  => $counts[Reports::SEVERITY_CRITICAL],
            'high_count'      => $counts[Reports::SEVERITY_HIGH],
            'medium_count'    => $counts[Reports::SEVERITY_MEDIUM],
            'low_count'       => $counts[Reports::SEVERITY_LOW],
            'resolved_count'  => $resolved,
        ], ['id' => $importId]);
        AuditLog::record('import', self::class, $importId, 0, 'success', [
            'scanner'  => $scanner,
            'file'     => $row['file_name'],
            'findings' => $counts['total'],
            'replayed' => $stats['replayed'],
        ]);

        return [
            'scanner'   => $scanner,
            'findings'  => $counts['total'],
            'hosts'     => count($parsed['hosts']),
            'unmatched' => $unmatched,
            'resolved'  => $resolved,
            'truncated' => (bool) $parsed['truncated'],
            'skipped'   => (int) $parsed['skipped'],
            'id'        => $importId,
        ] + $stats;
    }

    /**
     * @param array<string, mixed> $finding
     */
    private static function storeFinding(
        array $finding,
        string $key,
        string $scanner,
        int $entity,
        int $importId,
        string $scanDate,
        int $computerId
    ): void {
        global $DB;

        $fields = [
            'hostname'      => (string) $finding['hostname'],
            'port'          => (string) $finding['port'],
            'test_id'       => (string) $finding['test_id'],
            'name'          => (string) $finding['name'],
            'family'        => (string) $finding['family'],
            'severity'      => (float) $finding['severity'],
            'qod'           => $finding['qod'],
            'cves'          => implode(',', $finding['cves']),
            'solution_type' => (string) $finding['solution_type'],
            'solution'      => (string) $finding['solution'],
        ];
        $current = $DB->request([
            'FROM'  => Tables::FINDINGS,
            'WHERE' => ['entities_id' => $entity, 'finding_key' => $key],
        ])->current();
        if ($current === null) {
            $DB->insert(Tables::FINDINGS, $fields + [
                'entities_id'  => $entity,
                'scanner'      => $scanner,
                'finding_key'  => $key,
                'host'         => (string) $finding['host'],
                'computers_id' => $computerId,
                'first_seen'   => $scanDate,
                'last_seen'    => $scanDate,
                Tables::FK     => $importId,
            ]);

            return;
        }
        $update = [];
        if ((int) $current['computers_id'] === 0 && $computerId > 0) {
            $update['computers_id'] = $computerId;
        }
        if ((string) $current['first_seen'] === '' || $scanDate < (string) $current['first_seen']) {
            $update['first_seen'] = $scanDate;
        }
        // Rapport plus ancien rejoué : le constat garde l'état de l'analyse la plus récente.
        if ($scanDate >= (string) $current['last_seen']) {
            $update = $fields + $update + [
                'last_seen'     => $scanDate,
                'is_resolved'   => 0,
                'date_resolved' => null,
                Tables::FK      => $importId,
            ];
        }
        if ($update !== []) {
            $DB->update(Tables::FINDINGS, $update, ['id' => (int) $current['id']]);
        }
    }

    /**
     * Ordinateur d'un hôte analysé, parmi ceux que la personne voit : rattachement déjà fait pour cet
     * hôte dans l'entité, sinon nom exact ou nom court (jamais par ressemblance, aucun si plusieurs
     * ordinateurs portent le nom), sinon adresse IP portée par un seul ordinateur. 0 sinon.
     */
    public static function matchHost(string $host, string $hostname, int $entity): int
    {
        global $DB;

        $previous = $DB->request([
            'SELECT' => ['computers_id'],
            'FROM'   => Tables::FINDINGS,
            'WHERE'  => ['entities_id' => $entity, 'host' => $host, 'computers_id' => ['>', 0]],
            'ORDER'  => 'last_seen DESC',
            'LIMIT'  => 1,
        ])->current();
        $candidates = [(int) ($previous['computers_id'] ?? 0)];
        foreach (array_unique(array_filter([$hostname, filter_var($host, FILTER_VALIDATE_IP) ? '' : $host])) as $name) {
            $candidates[] = SentinelOneAlert::matchComputer((string) $name, '');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $candidates[] = self::computerByIp($host);
        }
        foreach ($candidates as $id) {
            if ($id <= 0) {
                continue;
            }
            $computer = new Computer();
            if ($computer->getFromDB($id) && $computer->canViewItem()) {
                return $id;
            }

            // Ordinateur d'une autre entité : jamais rattaché ni dévoilé.
            return 0;
        }

        return 0;
    }

    /**
     * Ordinateur qui porte cette adresse IP dans l'inventaire GLPI, s'il est seul à la porter.
     */
    private static function computerByIp(string $ip): int
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT'   => ['mainitems_id'],
                'DISTINCT' => true,
                'FROM'     => 'glpi_ipaddresses',
                'WHERE'    => ['name' => $ip, 'mainitemtype' => Computer::class, 'is_deleted' => 0],
                'LIMIT'    => 2,
            ]) as $row
        ) {
            $ids[] = (int) $row['mainitems_id'];
        }

        return count($ids) === 1 ? $ids[0] : 0;
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * @param list<int>|null $entityIds périmètre d'un KRI, sinon entités de la session
     * @return array<string, mixed>
     */
    private static function restrict(string $table, ?array $entityIds): array
    {
        return $entityIds === null
            ? getEntitiesRestrictCriteria($table, '', '', false)
            : [$table . '.entities_id' => $entityIds === [] ? [-1] : $entityIds];
    }

    /**
     * Imports visibles, les plus récents d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function imports(array $where = []): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::IMPORTS,
                'WHERE' => $where + self::restrict(Tables::IMPORTS, null),
                'ORDER' => ['scan_start DESC', 'id DESC'],
                'LIMIT' => self::MAX_HISTORY,
            ]) as $row
        ) {
            $row['id'] = (int) $row['id'];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Constats visibles (entités de la session ou périmètre donné), les plus graves d'abord.
     *
     * @param array<string, mixed> $where
     * @param list<int>|null       $entityIds
     * @return list<array<string, mixed>>
     */
    public static function findings(array $where = [], ?array $entityIds = null, int $limit = self::MAX_ROWS): array
    {
        global $DB;

        if ($entityIds === null && !self::canView()) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::FINDINGS,
                'WHERE' => $where + self::restrict(Tables::FINDINGS, $entityIds),
                'ORDER' => ['is_resolved ASC', 'severity DESC', 'host ASC', 'id ASC'],
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $row['id'] = (int) $row['id'];
            $row['computers_id'] = (int) $row['computers_id'];
            $row['severity'] = (float) $row['severity'];
            $row['level'] = Reports::severityLevel($row['severity']) ?? Reports::SEVERITY_LOW;
            $row['cve_list'] = array_values(array_filter(explode(',', (string) $row['cves'])));
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getFinding(int $id): ?array
    {
        return $id > 0 ? (self::findings(['id' => $id], null, 1)[0] ?? null) : null;
    }

    /**
     * Constats ouverts par gravité (synthèse de la page, preuve, KRI).
     *
     * @param list<int>|null $entityIds
     * @return array<string, int>
     */
    public static function openCounts(?array $entityIds = null): array
    {
        global $DB;

        $counts = array_fill_keys(Reports::SEVERITIES, 0) + ['total' => 0, 'hosts' => 0, 'unmatched' => 0];
        if ($entityIds === null && !self::canView()) {
            return $counts;
        }
        $hosts = [];
        $unmatched = [];
        foreach (
            $DB->request([
                'SELECT' => ['severity', 'host', 'computers_id'],
                'FROM'   => Tables::FINDINGS,
                'WHERE'  => ['is_resolved' => 0] + self::restrict(Tables::FINDINGS, $entityIds),
            ]) as $row
        ) {
            $level = Reports::severityLevel((float) $row['severity']);
            if ($level === null) {
                continue;
            }
            $counts[$level]++;
            $counts['total']++;
            $hosts[(string) $row['host']] = true;
            if ((int) $row['computers_id'] === 0) {
                $unmatched[(string) $row['host']] = true;
            }
        }
        $counts['hosts'] = count($hosts);
        $counts['unmatched'] = count($unmatched);

        return $counts;
    }

    /**
     * KRI : constats critiques ou élevés encore ouverts sur le périmètre, null sans import.
     *
     * @param list<int> $entityIds
     */
    public static function openHighFindings(array $entityIds): ?float
    {
        if ($entityIds === [] || countElementsInTable(Tables::IMPORTS, ['entities_id' => $entityIds]) === 0) {
            return null;
        }
        $counts = self::openCounts($entityIds);

        return (float) ($counts[Reports::SEVERITY_CRITICAL] + $counts[Reports::SEVERITY_HIGH]);
    }

    /**
     * Hôtes dont les constats ouverts ne sont rattachés à aucun ordinateur.
     *
     * @return list<array{host: string, hostname: string, count: int, severity: float}>
     */
    public static function unmatchedHosts(): array
    {
        $hosts = [];
        foreach (self::findings(['computers_id' => 0, 'is_resolved' => 0], null, Reports::MAX_FINDINGS) as $row) {
            $host = (string) $row['host'];
            $hosts[$host] ??= [
                'host' => $host, 'hostname' => (string) $row['hostname'], 'count' => 0, 'severity' => 0.0,
            ];
            $hosts[$host]['count']++;
            $hosts[$host]['severity'] = max($hosts[$host]['severity'], $row['severity']);
        }
        usort(
            $hosts,
            static fn (array $a, array $b): int => [$b['severity'], $a['host']] <=> [$a['severity'], $b['host']]
        );

        return array_slice($hosts, 0, self::MAX_ROWS);
    }

    /**
     * Constats ouverts d'un ordinateur (onglet Sécurité 360°).
     *
     * @return list<array<string, mixed>>
     */
    public static function getForItem(CommonDBTM $item): array
    {
        if (!$item instanceof Computer || $item->isNewItem()) {
            return [];
        }

        return self::findings(['computers_id' => (int) $item->getID(), 'is_resolved' => 0]);
    }

    // ------------------------------------------------------------------ Actions.

    /**
     * Rattache à la main les constats d'un hôte non rapproché à un ordinateur visible.
     */
    public static function linkHost(string $host, int $computerId): bool
    {
        global $DB;

        $computer = new Computer();
        if (!self::canManage() || $host === '' || !$computer->getFromDB($computerId) || !$computer->canViewItem()) {
            return false;
        }
        $ids = array_column(self::findings(['host' => $host, 'computers_id' => 0], null, Reports::MAX_FINDINGS), 'id');
        if ($ids === []) {
            return false;
        }
        $DB->update(Tables::FINDINGS, ['computers_id' => $computerId], ['id' => $ids]);
        AuditLog::record('update', Computer::class, $computerId, 0, 'success', [
            'scanner_findings' => count($ids),
            'host'             => $host,
        ]);

        return true;
    }

    /**
     * Supprime un import et les constats que seul cet import a vus en dernier.
     */
    public static function deleteImport(int $importId): bool
    {
        global $DB;

        if (!self::canManage() || $importId <= 0 || self::imports(['id' => $importId]) === []) {
            return false;
        }
        $DB->delete(Tables::FINDINGS, [Tables::FK => $importId]);
        $DB->delete(Tables::IMPORTS, ['id' => $importId]);
        AuditLog::record('purge', self::class, $importId);

        return true;
    }

    /**
     * Ticket de remédiation d'un constat ouvert et rattaché, créé seulement à la demande : un seul par
     * constat. Renvoie l'identifiant du ticket, 0 sans droits ou sans ordinateur.
     */
    public static function createTicket(int $findingId): int
    {
        global $DB;

        $finding = self::getFinding($findingId);
        if (
            $finding === null || !self::canManage() || !(bool) Session::haveRight(Ticket::$rightname, CREATE)
            || $finding['computers_id'] === 0 || (int) $finding['is_resolved'] === 1
        ) {
            return 0;
        }
        $existing = new Ticket();
        if ((int) $finding['tickets_id'] > 0 && $existing->getFromDB((int) $finding['tickets_id'])) {
            return (int) $finding['tickets_id'];
        }
        $computer = new Computer();
        if (!$computer->getFromDB($finding['computers_id']) || !$computer->canViewItem()) {
            return 0;
        }
        $ticketId = (int) (new Ticket())->add([
            'name'        => mb_substr(sprintf(
                __('Vulnerability found by the scanner: %1$s on %2$s', 'grcmanager'),
                (string) $finding['name'],
                (string) $computer->fields['name']
            ), 0, 255),
            'content'     => TemplateRenderer::getInstance()->render('@grcmanager/scanner_import_ticket.html.twig', [
                'finding'   => $finding,
                'scanner'   => Reports::label((string) $finding['scanner']),
                'levels'    => self::severityLabels(),
                'last_seen' => (string) Html::convDateTime((string) $finding['last_seen']),
            ]),
            'type'        => Ticket::INCIDENT_TYPE,
            'entities_id' => (int) $computer->fields['entities_id'],
        ]);
        if ($ticketId <= 0) {
            return 0;
        }
        (new Item_Ticket())->add([
            'itemtype'      => Computer::class,
            'items_id'      => $finding['computers_id'],
            'tickets_id'    => $ticketId,
            '_disablenotif' => true,
        ]);
        $DB->update(Tables::FINDINGS, ['tickets_id' => $ticketId], ['id' => $findingId]);

        return $ticketId;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @param array<string, mixed> $filters severity, status (open, resolved, all), computers_id
     * @return array<string, mixed>
     */
    public static function pageData(array $filters = []): array
    {
        $where = [];
        $status = in_array($filters['status'] ?? '', ['open', 'resolved', 'all'], true) ? $filters['status'] : 'open';
        if ($status !== 'all') {
            $where['is_resolved'] = $status === 'resolved' ? 1 : 0;
        }
        $severity = in_array($filters['severity'] ?? '', Reports::SEVERITIES, true) ? $filters['severity'] : '';
        $computerId = (int) ($filters['computers_id'] ?? 0);
        $computerName = '';
        if ($computerId > 0) {
            $computer = new Computer();
            if ($computer->getFromDB($computerId) && $computer->canViewItem()) {
                $where['computers_id'] = $computerId;
                $computerName = (string) $computer->fields['name'];
            } else {
                $computerId = 0;
            }
        }
        $rows = self::findings($where);
        if ($severity !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['level'] === $severity));
        }
        $names = [];
        foreach ($rows as &$row) {
            $id = $row['computers_id'];
            if ($id > 0 && !isset($names[$id])) {
                $computer = new Computer();
                $names[$id] = $computer->getFromDB($id) ? (string) $computer->fields['name'] : '';
            }
            $row['computer_name'] = $id > 0 ? ($names[$id] ?? '') : '';
            $row['computer_url'] = $id > 0 ? Computer::getFormURLWithID($id) : '';
            $row['ticket_url'] = (int) $row['tickets_id'] > 0 ? Ticket::getFormURLWithID((int) $row['tickets_id']) : '';
            $row['last_seen_label'] = (string) Html::convDateTime((string) $row['last_seen']);
            $row['scanner_label'] = Reports::label((string) $row['scanner']);
        }
        unset($row);
        $imports = self::imports();
        foreach ($imports as $index => &$import) {
            $older = $imports[$index + 1] ?? null;
            $import['trend'] = $older === null ? null : (int) $import['finding_count'] - (int) $older['finding_count'];
            $import['scan_label'] = (string) Html::convDateTime((string) $import['scan_start']);
            $import['scanner_label'] = Reports::label((string) $import['scanner']);
        }
        unset($import);

        return [
            'summary'       => self::openCounts(),
            'levels'        => self::severityLabels(),
            'rows'          => $rows,
            'imports'       => $imports,
            'unmatched'     => self::unmatchedHosts(),
            'filters'       => ['status' => $status, 'severity' => $severity, 'computers_id' => $computerId],
            'computer_name' => $computerName,
            'can_manage'    => self::canManage(),
            'can_ticket'    => self::canManage() && (bool) Session::haveRight(Ticket::$rightname, CREATE),
            'action'        => self::getSearchURL(),
            'example_url'   => self::getSearchURL() . '?example=openvas',
            'max_mb'        => max(1, (int) floor(self::maxUploadBytes() / 1048576)),
            'max_rows'      => self::MAX_ROWS,
            'formats'       => array_map(static fn (string $parser): string => $parser::label(), Reports::parsers()),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function showPage(array $filters = []): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/scanner_import.html.twig', self::pageData($filters));
    }

    // ------------------------------------------------------------------ Preuve.

    /**
     * Preuve de la mesure A.8.8 : constats ouverts par gravité et date de la dernière analyse, null
     * pour une autre mesure ou sans import visible.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::EVIDENCE_CONTROLS, true) || !self::canView()) {
            return null;
        }
        $imports = self::imports();
        if ($imports === []) {
            return null;
        }

        return [
            'counts'    => self::openCounts(),
            'levels'    => self::severityLabels(),
            'last_scan' => (string) Html::convDateTime((string) $imports[0]['scan_start']),
            'imports'   => count($imports),
            'url'       => self::getSearchURL(),
        ];
    }
}
