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
use Computer;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\ConfigScanTables as Tables;
use GlpiPlugin\Grcmanager\Services\Compliance\ConfigScanRules as Rules;
use GlpiPlugin\Grcmanager\Services\Compliance\XccdfParser;
use Html;
use Item_Ticket;
use Session;
use Ticket;

/**
 * Issue #781 : conformité de configuration (mesure A.8.9) d'après les résultats XCCDF/ARF
 * d'OpenSCAP (guides CIS, ANSSI, DISA STIG… exécutés par l'outil), importés par fichier.
 *
 * - Chaque résultat est rapproché d'un ordinateur GLPI visible par son nom (nom d'hôte, cible, nom
 *   complet ou nom court) ; sinon il est listé « non rapproché » et peut être rattaché à la main.
 * - Taux par ordinateur et par profil, évolution depuis l'analyse précédente, règles en échec les
 *   plus fréquentes, historique de chaque ordinateur.
 * - Preuve de la mesure A.8.9, indicateur de risque, ticket de remédiation proposé, créé seulement
 *   sur un clic : rien n'est modifié automatiquement, ni dans GLPI ni sur les machines.
 *
 * Droits : droit du plugin et lecture native des ordinateurs, rapports des entités de la session ;
 * modification du plugin pour importer, rattacher, supprimer et créer un ticket.
 */
class ConfigurationCompliance extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Rapports lus au plus pour la page (les plus récents d'abord). */
    public const MAX_REPORTS = 5000;

    /** Ordinateurs listés au plus sur la page. */
    public const MAX_ROWS = 300;

    /** Règles en échec reportées au plus dans un ticket. */
    private const TICKET_RULES_MAX = 100;

    /** Mesures dont la page est une preuve. */
    public const EVIDENCE_CONTROLS = ['A.8.9'];

    public static function getTypeName($nb = 0)
    {
        return __('Configuration compliance', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/configcompliance.php';
    }

    public static function getReportURL(int $reportId): string
    {
        return self::getSearchURL() . '?report=' . $reportId;
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Computer::canView();
    }

    /**
     * Import, rattachement, suppression et ticket de remédiation.
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

        // Sous-page de la rubrique « Sécurité technique » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Taille maximale acceptée : la plus petite de la limite du plugin et de celle de PHP.
     */
    public static function maxUploadBytes(): int
    {
        $limit = XccdfParser::MAX_BYTES;
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes((string) ini_get($key));
            if ($bytes > 0) {
                $limit = min($limit, $bytes);
            }
        }

        return $limit;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([kmg]?)/i', $value, $match)) {
            return 0;
        }
        $number = (int) $match[1];

        return match (strtolower($match[2])) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, string>
     */
    public static function severityLabels(): array
    {
        return [
            'high'    => __('High', 'grcmanager'),
            'medium'  => __('Medium', 'grcmanager'),
            'low'     => __('Low', 'grcmanager'),
            'info'    => __('Information', 'grcmanager'),
            'unknown' => __('Unknown', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function resultLabels(): array
    {
        return [
            'fail'  => __('Failed', 'grcmanager'),
            'error' => __('Error during the check', 'grcmanager'),
        ];
    }

    /**
     * Message d'erreur d'import, pour une personne qui ne connaît pas XCCDF.
     */
    public static function errorMessage(string $error): string
    {
        return match ($error) {
            XccdfParser::ERROR_TOO_LARGE => __('The file is too large.', 'grcmanager'),
            XccdfParser::ERROR_DOCTYPE   => __(
                'The file contains a document type or entity declaration, refused for security reasons. '
                    . 'OpenSCAP never writes one: export the results again with oscap.',
                'grcmanager'
            ),
            XccdfParser::ERROR_NO_RESULT => __(
                'No scan result was found in the file. Choose the results file (option --results or '
                    . '--results-arf of oscap), not the benchmark or the HTML report.',
                'grcmanager'
            ),
            XccdfParser::ERROR_INVALID   => __('The file is not valid XML, or it is truncated.', 'grcmanager'),
            default                      => __('The file could not be received.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Import.

    /**
     * Importe un fichier XCCDF ou ARF déjà reçu par le serveur.
     *
     * @return array{error: string, reports: int, matched: int, unmatched: int, duplicates: int,
     *     truncated: bool}
     */
    public static function importFile(string $path, string $fileName): array
    {
        $stats = [
            'error' => '', 'reports' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'truncated' => false,
        ];
        if (!self::canManage()) {
            return ['error' => 'rights'] + $stats;
        }
        $parsed = XccdfParser::parseFile($path);
        if ($parsed['error'] !== XccdfParser::ERROR_NONE) {
            return ['error' => $parsed['error']] + $stats;
        }
        $stats['truncated'] = $parsed['truncated'];
        $fileName = mb_substr(basename($fileName), 0, 255);
        foreach ($parsed['reports'] as $report) {
            $result = self::storeReport($report, $fileName);
            if ($result === 'duplicate') {
                $stats['duplicates']++;
                continue;
            }
            $stats['reports']++;
            $stats[$result === 'matched' ? 'matched' : 'unmatched']++;
        }
        AuditLog::record('import', self::class, 0, 0, 'success', [
            'file'       => $fileName,
            'reports'    => $stats['reports'],
            'matched'    => $stats['matched'],
            'duplicates' => $stats['duplicates'],
        ]);

        return $stats;
    }

    /**
     * @param array<string, mixed> $report
     * @return string 'matched', 'unmatched' ou 'duplicate'
     */
    private static function storeReport(array $report, string $fileName): string
    {
        global $DB;

        $computer = self::matchVisibleComputer($report);
        $hostname = Rules::normalizeHost(
            (string) ($report['hostname'] !== '' ? $report['hostname'] : $report['target'])
        );
        $scanDate = (string) ($report['start_time'] !== '' ? $report['start_time'] : $report['end_time']);
        if ($scanDate !== '') {
            $duplicate = countElementsInTable(Tables::REPORTS, [
                'hostname'     => $hostname,
                'profile_id'   => (string) $report['profile_id'],
                'benchmark_id' => (string) $report['benchmark_id'],
                'scan_date'    => $scanDate,
            ] + getEntitiesRestrictCriteria(Tables::REPORTS, '', '', false));
            if ($duplicate > 0) {
                return 'duplicate';
            }
        }
        $counts = $report['counts'];
        $DB->insert(Tables::REPORTS, [
            'entities_id'     => $computer === null
                ? Session::getActiveEntity()
                : (int) $computer->fields['entities_id'],
            'computers_id'    => $computer === null ? 0 : (int) $computer->getID(),
            'hostname'        => $hostname,
            'fqdn'            => Rules::normalizeHost((string) $report['fqdn']),
            'address'         => (string) ($report['addresses'][0] ?? ''),
            'benchmark_id'    => (string) $report['benchmark_id'],
            'benchmark_title' => (string) $report['benchmark_title'],
            'profile_id'      => (string) $report['profile_id'],
            'profile_title'   => (string) $report['profile_title'],
            'scanner'         => (string) $report['scanner'],
            'scan_date'       => $scanDate !== '' ? $scanDate : (Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'pass_count'      => (int) $counts['pass'],
            'fail_count'      => (int) $counts['fail'],
            'error_count'     => (int) $counts['error'],
            'fixed_count'     => (int) $counts['fixed'],
            'other_count'     => (int) ($counts['unknown'] + $counts['notapplicable'] + $counts['notchecked']
                + $counts['notselected'] + $counts['informational'] + $counts['other']),
            'high_fail_count' => (int) $report['high_failed'],
            'score'           => $report['score'],
            'score_max'       => $report['score_max'],
            'file_name'       => $fileName,
            'is_truncated'    => $report['failed_truncated'] ? 1 : 0,
            'users_id'        => (int) Session::getLoginUserID(),
            'date_creation'   => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
        ]);
        $reportId = (int) $DB->insertId();
        foreach ($report['failed'] as $rule) {
            $DB->insert(Tables::FAILURES, [
                Tables::FK => $reportId,
                'rule_id'  => (string) $rule['rule_id'],
                'title'    => (string) $rule['title'],
                'severity' => (string) $rule['severity'],
                'result'   => (string) $rule['result'],
            ]);
        }

        return $computer === null ? 'unmatched' : 'matched';
    }

    /**
     * Ordinateur GLPI d'un rapport, parmi ceux que la personne voit : même règle que les autres
     * imports (nom exact ou nom court, jamais par ressemblance, aucun rapprochement si plusieurs
     * ordinateurs portent le nom).
     *
     * @param array<string, mixed> $report
     */
    public static function matchVisibleComputer(array $report): ?Computer
    {
        foreach (Rules::candidateNames($report) as $name) {
            $id = SentinelOneAlert::matchComputer($name, '');
            if ($id <= 0) {
                continue;
            }
            $computer = new Computer();
            if ($computer->getFromDB($id) && $computer->canViewItem()) {
                return $computer;
            }

            // Ordinateur d'une autre entité : jamais rattaché ni dévoilé.
            return null;
        }

        return null;
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * Rapports visibles (entités de la session ou périmètre donné), les plus récents d'abord, avec
     * leur taux.
     *
     * @param array<string, mixed> $where
     * @param list<int>|null       $entityIds périmètre d'un KRI, sinon entités de la session
     * @return list<array<string, mixed>>
     */
    public static function reports(array $where = [], ?array $entityIds = null): array
    {
        global $DB;

        if ($entityIds === null && !self::canView()) {
            return [];
        }
        $restrict = $entityIds === null
            ? getEntitiesRestrictCriteria(Tables::REPORTS, '', '', false)
            : ['entities_id' => $entityIds === [] ? [-1] : $entityIds];
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::REPORTS,
                'WHERE' => $where + $restrict,
                'ORDER' => ['scan_date DESC', 'id DESC'],
                'LIMIT' => self::MAX_REPORTS,
            ]) as $row
        ) {
            $row['id'] = (int) $row['id'];
            $row['computers_id'] = (int) $row['computers_id'];
            $row['rate'] = Rules::rate(
                (int) $row['pass_count'],
                (int) $row['fail_count'],
                (int) $row['error_count'],
                (int) $row['fixed_count']
            );
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getReport(int $reportId): ?array
    {
        if ($reportId <= 0) {
            return null;
        }
        $rows = self::reports(['id' => $reportId]);

        return $rows[0] ?? null;
    }

    /**
     * Règles en échec d'un ou plusieurs rapports, gravité haute d'abord.
     *
     * @param list<int> $reportIds
     * @return list<array<string, mixed>>
     */
    public static function failures(array $reportIds): array
    {
        global $DB;

        if ($reportIds === []) {
            return [];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => Tables::FAILURES, 'WHERE' => [Tables::FK => $reportIds]]) as $row) {
            $rows[] = [
                'report'   => (int) $row[Tables::FK],
                'rule_id'  => (string) $row['rule_id'],
                'title'    => (string) $row['title'],
                'severity' => (string) $row['severity'],
                'result'   => (string) $row['result'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [
            Rules::SEVERITY_ORDER[$a['severity']] ?? 9, $a['title'], $a['rule_id'],
        ] <=> [
            Rules::SEVERITY_ORDER[$b['severity']] ?? 9, $b['title'], $b['rule_id'],
        ]);

        return $rows;
    }

    /**
     * Synthèse sur un périmètre d'entités (KRI) ou sur les entités de la session.
     *
     * @param list<int>|null $entityIds
     * @return array{targets: int, average: ?int, below_target: int, with_high: int, unmatched: int,
     *     high_rate: ?int}
     */
    public static function summary(?array $entityIds = null): array
    {
        return Rules::summary(Rules::latestPerTarget(self::reports([], $entityIds)));
    }

    /**
     * KRI : part des ordinateurs analysés dont la dernière analyse a une règle de gravité haute en
     * échec (%), null sans analyse sur le périmètre.
     *
     * @param list<int> $entityIds
     */
    public static function highFailureRate(array $entityIds): ?float
    {
        if ($entityIds === []) {
            return null;
        }
        $rate = self::summary($entityIds)['high_rate'];

        return $rate === null ? null : (float) $rate;
    }

    // ------------------------------------------------------------------ Actions.

    /**
     * Rattache à la main un rapport non rapproché (et ceux du même nom d'hôte) à un ordinateur visible.
     */
    public static function linkComputer(int $reportId, int $computerId): bool
    {
        global $DB;

        $report = self::getReport($reportId);
        $computer = new Computer();
        if (
            !self::canManage() || $report === null || $report['computers_id'] !== 0
            || !$computer->getFromDB($computerId) || !$computer->canViewItem()
        ) {
            return false;
        }
        $ids = array_column(self::reports(['computers_id' => 0, 'hostname' => (string) $report['hostname']]), 'id');
        $DB->update(Tables::REPORTS, [
            'computers_id' => $computerId,
            'entities_id'  => (int) $computer->fields['entities_id'],
        ], ['id' => $ids === [] ? [$reportId] : $ids]);
        AuditLog::record('update', Computer::class, $computerId, 0, 'success', [
            'configuration_scan_reports' => $ids,
            'hostname'                   => (string) $report['hostname'],
        ]);

        return true;
    }

    public static function deleteReport(int $reportId): bool
    {
        global $DB;

        if (!self::canManage() || self::getReport($reportId) === null) {
            return false;
        }
        $DB->delete(Tables::FAILURES, [Tables::FK => $reportId]);
        $DB->delete(Tables::REPORTS, ['id' => $reportId]);
        AuditLog::record('purge', self::class, $reportId);

        return true;
    }

    /**
     * Ticket de remédiation d'un rapport rapproché, créé seulement à la demande : un seul par rapport.
     * Renvoie l'identifiant du ticket, 0 sans écart ou sans droits.
     */
    public static function createTicket(int $reportId): int
    {
        global $DB;

        $report = self::getReport($reportId);
        if (
            $report === null || !self::canManage() || !(bool) Session::haveRight(Ticket::$rightname, CREATE)
            || $report['computers_id'] === 0
        ) {
            return 0;
        }
        $existing = new Ticket();
        if ((int) $report['tickets_id'] > 0 && $existing->getFromDB((int) $report['tickets_id'])) {
            return (int) $report['tickets_id'];
        }
        $failures = self::failures([$reportId]);
        if ($failures === []) {
            return 0;
        }
        $computer = new Computer();
        if (!$computer->getFromDB($report['computers_id']) || !$computer->canViewItem()) {
            return 0;
        }
        $shown = array_slice($failures, 0, self::TICKET_RULES_MAX);
        $ticketId = (int) (new Ticket())->add([
            'name'        => sprintf(
                __('Configuration remediation: %1$s (%2$d rule(s) failed)', 'grcmanager'),
                (string) $computer->fields['name'],
                count($failures)
            ),
            'content'     => TemplateRenderer::getInstance()->render(
                '@grcmanager/configuration_compliance_ticket.html.twig',
                [
                    'report'     => $report,
                    'rules'      => $shown,
                    'more'       => count($failures) - count($shown),
                    'severities' => self::severityLabels(),
                    'results'    => self::resultLabels(),
                    'scan_date'  => (string) Html::convDateTime((string) $report['scan_date']),
                ]
            ),
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => (int) $computer->fields['entities_id'],
        ]);
        if ($ticketId <= 0) {
            return 0;
        }
        (new Item_Ticket())->add([
            'itemtype'      => Computer::class,
            'items_id'      => $report['computers_id'],
            'tickets_id'    => $ticketId,
            '_disablenotif' => true,
        ]);
        $DB->update(Tables::REPORTS, ['tickets_id' => $ticketId], ['id' => $reportId]);

        return $ticketId;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorate(array $row, ?int $previous = null): array
    {
        $row['computer_name'] = '';
        $row['computer_url'] = '';
        if ($row['computers_id'] > 0) {
            $computer = new Computer();
            if ($computer->getFromDB($row['computers_id'])) {
                $row['computer_name'] = (string) $computer->fields['name'];
                $row['computer_url'] = Computer::getFormURLWithID($row['computers_id']);
            }
        }
        $row['color'] = Rules::rateColor($row['rate']);
        $row['trend'] = Rules::trend($previous, $row['rate']);
        $row['scan_date_label'] = (string) Html::convDateTime((string) $row['scan_date']);
        $row['url'] = self::getReportURL($row['id']);
        $row['ticket_url'] = (int) $row['tickets_id'] > 0 ? Ticket::getFormURLWithID((int) $row['tickets_id']) : '';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $reports = self::reports();
        $latest = Rules::latestPerTarget($reports);
        $previous = Rules::previousRates($reports);
        // Les plus faibles d'abord.
        usort($latest, static fn (array $a, array $b): int => [$a['rate'] ?? 101, (string) $a['hostname']]
            <=> [$b['rate'] ?? 101, (string) $b['hostname']]);
        $matched = array_values(array_filter($latest, static fn (array $row): bool => $row['computers_id'] > 0));
        $unmatched = array_values(array_filter($latest, static fn (array $row): bool => $row['computers_id'] === 0));
        $targets = [];
        foreach ($latest as $row) {
            $targets[$row['id']] = $row['computers_id'] > 0 ? 'c' . $row['computers_id'] : 'h' . $row['hostname'];
        }
        $failures = array_map(static fn (array $failure): array => $failure + [
            'target' => $targets[$failure['report']] ?? '',
        ], self::failures(array_keys($targets)));

        return [
            'summary'      => Rules::summary($latest),
            'target_rate'  => Rules::TARGET_RATE,
            'rows'         => array_map(
                static fn (array $row): array => self::decorate($row, $previous[$row['id']] ?? null),
                array_slice($matched, 0, self::MAX_ROWS)
            ),
            'total_rows'   => count($matched),
            'unmatched'    => array_map(
                static fn (array $row): array => self::decorate($row),
                array_slice($unmatched, 0, self::MAX_ROWS)
            ),
            'top_rules'    => Rules::topFailing($failures, 10),
            'severities'   => self::severityLabels(),
            'can_manage'   => self::canManage(),
            'can_ticket'   => self::canManage() && (bool) Session::haveRight(Ticket::$rightname, CREATE),
            'action'       => self::getSearchURL(),
            'example_url'  => self::getSearchURL() . '?example=1',
            'max_mb'       => max(1, (int) floor(self::maxUploadBytes() / 1048576)),
            'report_count' => count($reports),
        ];
    }

    /**
     * Détail d'un rapport : règles en échec et historique de la même cible.
     *
     * @return array<string, mixed>|null
     */
    public static function reportData(int $reportId): ?array
    {
        $report = self::getReport($reportId);
        if ($report === null) {
            return null;
        }
        $history = self::reports($report['computers_id'] > 0
            ? ['computers_id' => $report['computers_id']]
            : ['computers_id' => 0, 'hostname' => (string) $report['hostname']]);
        $previous = Rules::previousRates($history);

        return [
            'report'     => self::decorate($report, $previous[$reportId] ?? null),
            'failures'   => self::failures([$reportId]),
            'history'    => array_map(
                static fn (array $row): array => self::decorate($row, $previous[$row['id']] ?? null),
                $history
            ),
            'severities' => self::severityLabels(),
            'results'    => self::resultLabels(),
            'can_manage' => self::canManage(),
            'can_ticket' => self::canManage() && (bool) Session::haveRight(Ticket::$rightname, CREATE),
            'action'     => self::getSearchURL(),
            'back'       => self::getSearchURL(),
        ];
    }

    // ------------------------------------------------------------------ Preuve.

    /**
     * Preuve de la mesure A.8.9 : une liste de sources de conformité de configuration, chacune avec
     * ses badges. OpenSCAP est la première ; d'autres sources (configuration à risque de l'inventaire
     * Docker, issue #916) s'ajoutent dans evidenceSources() sans changer le gabarit. Null si la
     * mesure n'est pas concernée ou sans source visible.
     *
     * @return array{sources: list<array<string, mixed>>}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::EVIDENCE_CONTROLS, true)) {
            return null;
        }
        $sources = self::evidenceSources();

        return $sources === [] ? null : ['sources' => $sources];
    }

    /**
     * @return list<array{key: string, icon: string, label: string, badges: list<array{color: string,
     *     icon: string, text: string}>, note: string, url: string, link: string}>
     */
    public static function evidenceSources(): array
    {
        $sources = [];
        $openscap = self::openscapSource();
        if ($openscap !== null) {
            $sources[] = $openscap;
        }

        return $sources;
    }

    /**
     * @return array{key: string, icon: string, label: string, badges: list<array{color: string,
     *     icon: string, text: string}>, note: string, url: string, link: string}|null
     */
    private static function openscapSource(): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $reports = self::reports();
        $summary = Rules::summary(Rules::latestPerTarget($reports));
        $source = [
            'key'    => 'openscap',
            'icon'   => self::getIcon(),
            'label'  => __('OpenSCAP scans (XCCDF/ARF)', 'grcmanager'),
            'badges' => [],
            'note'   => '',
            'url'    => self::getSearchURL(),
            'link'   => __('View the details', 'grcmanager'),
        ];
        if ($reports === []) {
            $source['badges'][] = [
                'color' => 'secondary', 'icon' => 'ti ti-file-off', 'text' => __('No scan imported yet', 'grcmanager'),
            ];
            $source['link'] = __('Import results', 'grcmanager');

            return $source;
        }
        $source['badges'][] = [
            'color' => Rules::rateColor($summary['average']),
            'icon'  => 'ti ti-chart-pie',
            'text'  => $summary['average'] === null
                ? __('Average compliance: not measured', 'grcmanager')
                : sprintf(__('Average compliance: %d%%', 'grcmanager'), $summary['average']),
        ];
        $source['badges'][] = [
            'color' => 'secondary',
            'icon'  => 'ti ti-device-desktop-analytics',
            'text'  => sprintf(__('%d machine(s) scanned', 'grcmanager'), $summary['targets']),
        ];
        $source['badges'][] = [
            'color' => $summary['below_target'] > 0 ? 'danger' : 'success',
            'icon'  => 'ti ti-target-arrow',
            'text'  => sprintf(
                __('%1$d below %2$d%%', 'grcmanager'),
                $summary['below_target'],
                Rules::TARGET_RATE
            ),
        ];
        $source['badges'][] = [
            'color' => $summary['with_high'] > 0 ? 'danger' : 'success',
            'icon'  => 'ti ti-alert-triangle',
            'text'  => sprintf(__('%d with a high severity rule failed', 'grcmanager'), $summary['with_high']),
        ];
        if ($summary['unmatched'] > 0) {
            $source['badges'][] = [
                'color' => 'warning',
                'icon'  => 'ti ti-link-off',
                'text'  => sprintf(__('%d not matched to a computer', 'grcmanager'), $summary['unmatched']),
            ];
        }
        $source['note'] = sprintf(
            __('Last scan on %s', 'grcmanager'),
            (string) Html::convDateTime((string) $reports[0]['scan_date'])
        );

        return $source;
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/configuration_compliance.html.twig', self::pageData());
    }

    public static function showReport(int $reportId): bool
    {
        $data = self::reportData($reportId);
        if ($data === null) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/configuration_compliance_report.html.twig', $data);

        return true;
    }
}
