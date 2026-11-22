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

use Appliance;
use CommonGLPI;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\CodeSecurityTables;
use GlpiPlugin\Grcmanager\Install\SecureDevelopmentTables;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityClient;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityConfig;
use GlpiPlugin\Grcmanager\Services\CodeSecurity\CodeSecurityRules as Rules;
use Html;
use Session;

/**
 * Issue #858 : sécurité du code des applications du registre (#777), lue en LECTURE SEULE dans
 * GitHub et GitLab, pour les mesures A.8.28 (codage sécurisé) et A.8.29 (tests de sécurité).
 *
 * - Pour chaque application dont la fiche « Développement sécurisé » donne un dépôt GitHub ou
 *   GitLab : alertes ouvertes des dépendances vulnérables, des secrets exposés et de l'analyse du
 *   code, avec les analyses non activées. Lecture sur clic, ou chaque jour par la tâche automatique
 *   (désactivée par défaut) ; une lecture en échec ne remplace pas la précédente.
 * - Alertes par gravité et ancienneté, échéance de la politique de correction des vulnérabilités
 *   (#601) ; un secret exposé que rien ne dit révoqué est signalé en priorité.
 * - Résumé en onglet de l'application, deux KRI, cadre de preuve des mesures A.8.28 et A.8.29.
 *
 * Droits : droit du plugin et lecture native des applications, dans les entités de la session ;
 * modification du plugin pour la connexion, la lecture et l'effacement. Rien n'est jamais modifié
 * dans GitHub ni GitLab, et rien n'est créé automatiquement dans GLPI.
 */
class CodeSecurity extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const CRON_NAME = 'codesecurity';

    /** Applications lues au plus par lecture (trois appels par dépôt GitHub). */
    public const MAX_APPLICATIONS = 200;

    /** Alertes listées au plus sur la page (prioritaires et en retard d'abord). */
    public const MAX_ROWS = 300;

    /** Alertes listées au plus dans l'onglet d'une application. */
    public const MAX_TAB_ROWS = 100;

    /** @var array<string, array<string, mixed>> calculs de la requête, par périmètre */
    private static array $memo = [];

    public static function getTypeName($nb = 0)
    {
        return __('Code security', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-bug';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/codesecurity.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Appliance::canView();
    }

    /** Connexion, lecture et effacement des lectures. */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Mesures » (Menu::sections()), à côté du développement sécurisé.
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function reset(): void
    {
        self::$memo = [];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function categoryInfos(): array
    {
        return [
            Rules::CATEGORY_SECRET     => [
                'label' => __('Exposed secret', 'grcmanager'),
                'color' => 'red',
                'icon'  => 'ti ti-key',
                'help'  => __('Password, key or token written in the code', 'grcmanager'),
            ],
            Rules::CATEGORY_DEPENDENCY => [
                'label' => __('Vulnerable dependency', 'grcmanager'),
                'color' => 'orange',
                'icon'  => 'ti ti-packages',
                'help'  => __('Third-party library with a known vulnerability', 'grcmanager'),
            ],
            Rules::CATEGORY_CODE       => [
                'label' => __('Code analysis', 'grcmanager'),
                'color' => 'blue',
                'icon'  => 'ti ti-code',
                'help'  => __('Weakness found in the code itself', 'grcmanager'),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function severityInfos(): array
    {
        return [
            Rules::CRITICAL => ['label' => __('Critical', 'grcmanager'), 'color' => 'red'],
            Rules::HIGH     => ['label' => __('High', 'grcmanager'), 'color' => 'orange'],
            Rules::MEDIUM   => ['label' => __('Medium', 'grcmanager'), 'color' => 'yellow'],
            Rules::LOW      => ['label' => __('Low', 'grcmanager'), 'color' => 'secondary'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ageLabels(): array
    {
        return [
            Rules::AGE_RECENT => __('Less than 30 days', 'grcmanager'),
            Rules::AGE_MONTHS => __('30 to 90 days', 'grcmanager'),
            Rules::AGE_OLD    => __('More than 90 days', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function providerInfos(): array
    {
        return [
            Rules::PROVIDER_GITHUB => ['label' => 'GitHub', 'color' => 'dark', 'icon' => 'ti ti-brand-github'],
            Rules::PROVIDER_GITLAB => ['label' => 'GitLab', 'color' => 'orange', 'icon' => 'ti ti-brand-gitlab'],
        ];
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            CodeSecurityClient::ERROR_CONFIG       => __(
                'Enter a read-only token for GitHub or GitLab, then click "Read now".',
                'grcmanager'
            ),
            CodeSecurityClient::ERROR_UNAUTHORIZED => __(
                'The token was refused: check that it is still valid and that it can read the security alerts '
                    . 'of the repositories.',
                'grcmanager'
            ),
            CodeSecurityClient::ERROR_RATE_LIMIT   => __(
                'Too many requests: GitHub or GitLab asks to wait. Read again later.',
                'grcmanager'
            ),
            CodeSecurityClient::ERROR_INTERNAL     => __(
                'The server has an internal address, refused by default: allow internal addresses in the plugin '
                    . 'configuration page (Outgoing connections).',
                'grcmanager'
            ),
            CodeSecurityClient::ERROR_UNREACHABLE  => __(
                'The server could not be reached: check the address, the network access or the GLPI proxy.',
                'grcmanager'
            ),
            CodeSecurityClient::ERROR_REFUSED      => __(
                'Refused: the connector only reads the security alerts.',
                'grcmanager'
            ),
            'rights'                               => __('You are not allowed to do this.', 'grcmanager'),
            default                                => __('The server returned an unexpected answer.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * Applications du registre qui ont un dépôt : entités de la session, ou toutes (tâche
     * automatique, sans session).
     *
     * @return list<array{appliances_id: int, repository_url: string}>
     */
    private static function registeredRepositories(bool $automatic): array
    {
        global $DB;

        $profiles = SecureDevelopmentTables::APPLICATIONS;
        if (!$DB->tableExists($profiles)) {
            return [];
        }
        $where = ['glpi_appliances.is_deleted' => 0, ['NOT' => [$profiles . '.repository_url' => '']]];
        if (!$automatic) {
            $where = array_merge($where, getEntitiesRestrictCriteria('glpi_appliances', '', '', true));
        }
        $out = [];
        foreach (
            $DB->request([
                'SELECT'     => [$profiles . '.appliances_id', $profiles . '.repository_url'],
                'FROM'       => $profiles,
                'INNER JOIN' => ['glpi_appliances' => ['FKEY' => [
                    'glpi_appliances' => 'id',
                    $profiles         => 'appliances_id',
                ]]],
                'WHERE'      => $where,
                'ORDER'      => 'glpi_appliances.name',
                'LIMIT'      => self::MAX_APPLICATIONS,
            ]) as $row
        ) {
            $out[] = [
                'appliances_id'  => (int) $row['appliances_id'],
                'repository_url' => (string) $row['repository_url'],
            ];
        }

        return $out;
    }

    /**
     * Lit les alertes ouvertes du dépôt de chaque application. Pour un dépôt, seule une lecture
     * complète remplace les alertes connues. $automatic : tâche automatique (sans session, pas de
     * contrôle de droits).
     *
     * @return array{error: string, repositories: int, alerts: int, failed: int}
     */
    public static function read(?CodeSecurityClient $client = null, bool $automatic = false): array
    {
        $result = ['error' => '', 'repositories' => 0, 'alerts' => 0, 'failed' => 0];
        if (!$automatic && !self::canManage()) {
            return ['error' => 'rights'] + $result;
        }
        if ($client === null && !CodeSecurityConfig::isConfigured()) {
            return ['error' => CodeSecurityClient::ERROR_CONFIG] + $result;
        }
        $client ??= CodeSecurityConfig::client();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $githubHost = CodeSecurityConfig::githubHost();
        $gitlabHost = CodeSecurityConfig::gitlabHost();
        /** @var array<string, string> $blocked fournisseur => erreur qui arrête ses lectures */
        $blocked = [];
        $lastError = '';
        foreach (self::registeredRepositories($automatic) as $app) {
            $repo = Rules::parseRepository($app['repository_url'], $githubHost, $gitlabHost);
            if ($repo === null) {
                self::saveRepository($app['appliances_id'], '', '', Rules::REPO_UNSUPPORTED, '', null, $now);
                continue;
            }
            if (!$client->hasProvider($repo['provider'])) {
                self::saveRepository(
                    $app['appliances_id'],
                    $repo['provider'],
                    $repo['path'],
                    Rules::REPO_NOT_CONFIGURED,
                    '',
                    null,
                    $now
                );
                continue;
            }
            $fetch = isset($blocked[$repo['provider']])
                ? ['complete' => false, 'error' => $blocked[$repo['provider']]]
                : $client->fetch($repo['provider'], $repo['path']);
            if (!$fetch['complete']) {
                $error = $fetch['error'] !== '' ? $fetch['error'] : CodeSecurityClient::ERROR_INVALID;
                // Jeton refusé, quota atteint, serveur injoignable : inutile d'insister sur ses autres dépôts.
                if (
                    in_array($error, [
                        CodeSecurityClient::ERROR_UNAUTHORIZED, CodeSecurityClient::ERROR_RATE_LIMIT,
                        CodeSecurityClient::ERROR_UNREACHABLE, CodeSecurityClient::ERROR_INTERNAL,
                    ], true)
                ) {
                    $blocked[$repo['provider']] = $error;
                }
                self::saveRepository(
                    $app['appliances_id'],
                    $repo['provider'],
                    $repo['path'],
                    Rules::REPO_ERROR,
                    $error,
                    null,
                    $now
                );
                $result['failed']++;
                $lastError = $error;
                continue;
            }
            self::saveRepository(
                $app['appliances_id'],
                $repo['provider'],
                $repo['path'],
                Rules::REPO_OK,
                '',
                $fetch,
                $now
            );
            $result['repositories']++;
            $result['alerts'] += count($fetch['alerts']);
        }
        if ($result['repositories'] === 0 && $result['failed'] > 0) {
            $result['error'] = $lastError;
        }
        CodeSecurityConfig::saveLastRead([
            'date'         => $now,
            'error'        => $lastError,
            'repositories' => $result['repositories'],
            'alerts'       => $result['alerts'],
            'failed'       => $result['failed'],
        ]);
        AuditLog::record('import', self::class, 0, 0, $result['error'] === '' ? 'success' : 'failure', [
            'source'       => $automatic ? 'cron' : 'click',
            'repositories' => $result['repositories'],
            'alerts'       => $result['alerts'],
            'failed'       => $result['failed'],
        ]);
        self::reset();

        return $result;
    }

    /**
     * Dernière lecture du dépôt d'une application ; avec $fetch (lecture complète), ses alertes
     * ouvertes sont remplacées. Une erreur garde les alertes et la date de la lecture réussie.
     *
     * @param array{scans: array<string, string>, truncated: bool, alerts: list<array<string, string>>}|null $fetch
     */
    private static function saveRepository(
        int $appliancesId,
        string $provider,
        string $path,
        string $status,
        string $error,
        ?array $fetch,
        string $now
    ): void {
        global $DB;

        $fields = [
            'provider'     => $provider,
            'repository'   => mb_substr($path, 0, 255),
            'status'       => $status,
            'error'        => mb_substr($error, 0, 32),
            'date_attempt' => $now,
        ];
        if ($fetch !== null) {
            $fields += [
                'dependency_scan' => $fetch['scans'][Rules::CATEGORY_DEPENDENCY] ?? '',
                'secret_scan'     => $fetch['scans'][Rules::CATEGORY_SECRET] ?? '',
                'code_scan'       => $fetch['scans'][Rules::CATEGORY_CODE] ?? '',
                'truncated'       => $fetch['truncated'] ? 1 : 0,
                'date_read'       => $now,
            ];
        } elseif ($status !== Rules::REPO_ERROR) {
            // Dépôt non reconnu ou fournisseur sans jeton : plus rien de lu pour cette application.
            $fields += ['dependency_scan' => '', 'secret_scan' => '', 'code_scan' => '', 'truncated' => 0];
            $DB->delete(CodeSecurityTables::ALERTS, ['appliances_id' => $appliancesId]);
        }
        $existing = $DB->request([
            'SELECT' => 'id', 'FROM' => CodeSecurityTables::REPOSITORIES,
            'WHERE'  => ['appliances_id' => $appliancesId], 'LIMIT' => 1,
        ])->current();
        if ($existing !== null) {
            $DB->update(CodeSecurityTables::REPOSITORIES, $fields, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert(CodeSecurityTables::REPOSITORIES, ['appliances_id' => $appliancesId] + $fields);
        }
        if ($fetch === null) {
            return;
        }
        $DB->delete(CodeSecurityTables::ALERTS, ['appliances_id' => $appliancesId]);
        foreach ($fetch['alerts'] as $alert) {
            $DB->insert(CodeSecurityTables::ALERTS, [
                'appliances_id' => $appliancesId,
                'provider'      => $provider,
                'category'      => $alert['category'],
                'alert_key'     => mb_substr($alert['key'], 0, 32),
                'severity'      => $alert['severity'],
                'title'         => $alert['title'],
                'detail'        => $alert['detail'],
                'reference'     => $alert['reference'],
                'validity'      => $alert['validity'],
                'url'           => $alert['url'],
                'date_detected' => $alert['detected'] !== '' ? $alert['detected'] : $now,
                'date_import'   => $now,
            ]);
        }
    }

    /**
     * Efface les lectures des applications visibles (dépôts et alertes) ; rien n'est touché chez
     * GitHub ou GitLab.
     */
    public static function clear(): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $ids = array_keys(self::visibleApplications(null));
        if ($ids !== []) {
            $DB->delete(CodeSecurityTables::ALERTS, ['appliances_id' => $ids]);
            $DB->delete(CodeSecurityTables::REPOSITORIES, ['appliances_id' => $ids]);
        }
        AuditLog::record('purge', self::class, 0, 0, 'success', ['applications' => count($ids)]);
        self::reset();

        return true;
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Applications lisibles (non supprimées) : entités de la session, ou périmètre donné (KRI).
     *
     * @param list<int>|null $entities
     * @return array<int, array{name: string, entities_id: int}>
     */
    private static function visibleApplications(?array $entities): array
    {
        global $DB;

        $where = ['is_deleted' => 0];
        if ($entities === null) {
            $where += getEntitiesRestrictCriteria('glpi_appliances', '', '', true);
        } else {
            $where['entities_id'] = $entities === [] ? -1 : $entities;
        }
        $out = [];
        $criteria = ['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => 'glpi_appliances', 'WHERE' => $where];
        foreach ($DB->request($criteria) as $row) {
            $out[(int) $row['id']] = ['name' => (string) $row['name'], 'entities_id' => (int) $row['entities_id']];
        }

        return $out;
    }

    /**
     * Dépôts lus et alertes ouvertes des applications visibles, avec l'état de chaque alerte.
     *
     * @param list<int>|null $entities
     * @return array{repositories: array<int, array<string, mixed>>, alerts: list<array<string, mixed>>,
     *     readings: bool}
     */
    private static function compute(?array $entities): array
    {
        global $DB;

        $applications = self::visibleApplications($entities);
        $out = ['repositories' => [], 'alerts' => [], 'readings' => false];
        if ($applications === [] || !$DB->tableExists(CodeSecurityTables::REPOSITORIES)) {
            return $out;
        }
        $ids = array_keys($applications);
        $repositories = ['FROM' => CodeSecurityTables::REPOSITORIES, 'WHERE' => ['appliances_id' => $ids]];
        foreach ($DB->request($repositories) as $row) {
            $id = (int) $row['appliances_id'];
            $out['repositories'][$id] = $row + [
                'name'   => $applications[$id]['name'],
                'counts' => array_fill_keys(Rules::CATEGORIES, 0),
                'worst'  => array_fill_keys(Rules::CATEGORIES, ''),
                'overdue'  => 0,
                'priority' => 0,
            ];
            $out['readings'] = $out['readings'] || $row['date_read'] !== null;
        }
        $days = Rules::policyDays(ParkRemediation::getSettings()['days']);
        $today = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        foreach ($DB->request(['FROM' => CodeSecurityTables::ALERTS, 'WHERE' => ['appliances_id' => $ids]]) as $row) {
            $id = (int) $row['appliances_id'];
            $state = Rules::state(
                (string) $row['category'],
                (string) $row['severity'],
                (string) $row['validity'],
                (string) $row['date_detected'],
                $today,
                $days
            );
            $alert = $row + $state + ['application' => $applications[$id]['name']];
            $out['alerts'][] = $alert;
            if (isset($out['repositories'][$id])) {
                $repo = &$out['repositories'][$id];
                $category = (string) $row['category'];
                if (isset($repo['counts'][$category])) {
                    $repo['counts'][$category]++;
                    $repo['worst'][$category] = Rules::worstSeverity(
                        array_filter([$repo['worst'][$category], (string) $row['severity']])
                    );
                }
                $repo['overdue'] += $state['overdue'] ? 1 : 0;
                $repo['priority'] += $state['priority'] ? 1 : 0;
                unset($repo);
            }
        }
        usort($out['alerts'], static fn (array $a, array $b): int => Rules::rank($a) <=> Rules::rank($b));

        return $out;
    }

    /**
     * @param list<int>|null $entities
     * @return array{repositories: array<int, array<string, mixed>>, alerts: list<array<string, mixed>>,
     *     readings: bool}
     */
    private static function data(?array $entities = null): array
    {
        $key = $entities === null ? 'session' : implode(',', $entities);

        return self::$memo[$key] ??= self::compute($entities);
    }

    /**
     * Bilan : alertes ouvertes (gravité, famille, ancienneté), en retard, secrets prioritaires,
     * dépôts lus et analyses non activées.
     *
     * @param array{repositories: array<int, array<string, mixed>>, alerts: list<array<string, mixed>>,
     *     readings: bool}|null $data
     * @return array<string, mixed>
     */
    public static function summary(?array $data = null): array
    {
        $data ??= self::data();
        $summary = Rules::summarize($data['alerts']);
        $read = 0;
        $missing = array_fill_keys(Rules::CATEGORIES, 0);
        foreach ($data['repositories'] as $repo) {
            if ($repo['date_read'] === null) {
                continue;
            }
            $read++;
            foreach (Rules::CATEGORIES as $category) {
                $missing[$category] += ($repo[$category . '_scan'] ?? '') === Rules::SCAN_UNAVAILABLE ? 1 : 0;
            }
        }

        return $summary + [
            'repositories_read' => $read,
            'repositories'      => count($data['repositories']),
            'missing_scans'     => $missing,
            'missing_any'       => count(array_filter(
                $data['repositories'],
                static fn (array $repo): bool => $repo['date_read'] !== null && in_array(
                    Rules::SCAN_UNAVAILABLE,
                    [$repo['dependency_scan'], $repo['secret_scan'], $repo['code_scan']],
                    true
                )
            )),
        ];
    }

    /**
     * KRI : secrets exposés que rien ne dit révoqués, dans le périmètre (null sans lecture).
     *
     * @param list<int> $perimeter
     */
    public static function secretsNotRevoked(array $perimeter): ?float
    {
        $data = self::data($perimeter);

        return $data['readings'] ? (float) Rules::summarize($data['alerts'])['secrets_priority'] : null;
    }

    /**
     * KRI : alertes ouvertes au-delà du délai de la politique, dans le périmètre (null sans lecture).
     *
     * @param list<int> $perimeter
     */
    public static function overdueAlerts(array $perimeter): ?float
    {
        $data = self::data($perimeter);

        return $data['readings'] ? (float) Rules::summarize($data['alerts'])['overdue'] : null;
    }

    // ------------------------------------------------------------------ Tâche automatique.

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === self::CRON_NAME
            ? ['description' => __(
                'Code security: reads the security alerts of GitHub and GitLab (read only)',
                'grcmanager'
            )]
            : [];
    }

    public static function cronCodesecurity(CronTask $task): int
    {
        if (!CodeSecurityConfig::isEnabled() || !CodeSecurityConfig::isConfigured()) {
            return 0;
        }
        $result = self::read(null, true);
        if ($result['error'] !== '') {
            // Code d'erreur seulement : jamais un jeton.
            $task->log('Code security: ' . $result['error']);

            return 0;
        }
        $task->addVolume($result['alerts']);

        return 1;
    }

    /** La tâche automatique suit l'interrupteur du connecteur (désactivée par défaut). */
    public static function syncCronState(bool $enabled): void
    {
        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, self::CRON_NAME)) {
            $state = $enabled ? CronTask::STATE_WAITING : CronTask::STATE_DISABLE;
            $current = (int) $task->fields['state'];
            if ($current !== $state && !($enabled && $current === CronTask::STATE_RUNNING)) {
                $task->update(['id' => $task->getID(), 'state' => $state]);
            }
        }
    }

    // ------------------------------------------------------------------ Page.

    /**
     * Libellés d'affichage d'une alerte.
     *
     * @param array<string, mixed> $alert
     * @return array<string, mixed>
     */
    private static function decorate(array $alert): array
    {
        $alert['detected_label'] = $alert['date_detected'] !== null
            ? (string) Html::convDate((string) $alert['date_detected']) : '';
        $alert['due_label'] = (string) Html::convDate((string) $alert['due']);
        $alert['application_url'] = Appliance::getFormURLWithID((int) $alert['appliances_id']);
        $detail = (string) $alert['detail'];
        $alert['detail_label'] = match ($detail) {
            'push_protection_bypassed' => __('Push protection was bypassed', 'grcmanager'),
            'sast'                     => __('Static analysis (SAST)', 'grcmanager'),
            'dast'                     => __('Dynamic analysis (DAST)', 'grcmanager'),
            'dependency_scanning'      => __('Dependency analysis', 'grcmanager'),
            'container_scanning'       => __('Container image analysis', 'grcmanager'),
            'secret_detection'         => '',
            default                    => $detail,
        };

        return $alert;
    }

    /**
     * Dépôts lus, pour l'affichage (applications du registre sans lecture comprises).
     *
     * @param array<int, array<string, mixed>> $repositories
     * @return list<array<string, mixed>>
     */
    private static function repositoryRows(array $repositories): array
    {
        $rows = [];
        foreach ($repositories as $id => $repo) {
            $repo['application_url'] = Appliance::getFormURLWithID((int) $id);
            $repo['date_read_label'] = $repo['date_read'] !== null
                ? (string) Html::convDateTime((string) $repo['date_read']) : '';
            $repo['error_label'] = (string) $repo['error'] !== '' ? self::errorMessage((string) $repo['error']) : '';
            $rows[] = $repo;
        }
        // Secrets prioritaires, alertes en retard, puis nom.
        $rank = static fn (array $row): array => [
            -$row['priority'], -$row['overdue'], mb_strtolower((string) $row['name']),
        ];
        usort($rows, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $rows;
    }

    /**
     * Applications du registre visibles qui n'ont pas de dépôt renseigné.
     */
    private static function applicationsWithoutRepository(): int
    {
        if (!SecureDevelopment::canViewApplications()) {
            return 0;
        }

        return count(array_filter(
            SecureDevelopment::applications(),
            static fn (array $app): bool => trim((string) $app['repository_url']) === ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        global $CFG_GLPI;

        $data = self::data();
        $lastRead = CodeSecurityConfig::lastRead();
        $days = Rules::policyDays(ParkRemediation::getSettings()['days']);

        return [
            'summary'        => self::summary($data),
            'readings'       => $data['readings'],
            'repositories'   => self::repositoryRows($data['repositories']),
            'alerts'         => array_map(self::decorate(...), array_slice($data['alerts'], 0, self::MAX_ROWS)),
            'total_alerts'   => count($data['alerts']),
            'without_repository' => self::applicationsWithoutRepository(),
            'categories'     => self::categoryInfos(),
            'severities'     => self::severityInfos(),
            'ages'           => self::ageLabels(),
            'providers'      => self::providerInfos(),
            'policy_days'    => $days,
            // Les jetons ne sont jamais renvoyés, seulement leur présence.
            'connection'     => [
                'github_url'    => CodeSecurityConfig::rawGithubUrl(),
                'gitlab_url'    => CodeSecurityConfig::rawGitlabUrl(),
                'has_github'    => CodeSecurityConfig::hasGithubToken(),
                'has_gitlab'    => CodeSecurityConfig::hasGitlabToken(),
                'configured'    => CodeSecurityConfig::isConfigured(),
                'enabled'       => CodeSecurityConfig::isEnabled(),
                'allow_private' => \GlpiPlugin\Grcmanager\Compat\Http::allowsPrivateNetworks(),
            ],
            'last_read'      => $lastRead + [
                'date_label'  => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
                'error_label' => $lastRead['error'] !== '' ? self::errorMessage($lastRead['error']) : '',
            ],
            'can_manage'     => self::canManage(),
            'action'         => self::getSearchURL(),
            'register_url'   => SecureDevelopment::getPageURL(),
            'policy_url'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grc-remediation-settings',
            'config_url'     => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php#grc-outgoing-http',
            'kri_url'        => RiskIndicator::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/code_security.html.twig', self::pageData());
    }

    // ------------------------------------------------------------------ Onglet de l'application.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Appliance || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $count = count(self::alertsOf((int) $item->getID()));
        }

        return self::createTabEntry(self::getTypeName(), $count);
    }

    /**
     * Alertes ouvertes d'une application visible.
     *
     * @return list<array<string, mixed>>
     */
    private static function alertsOf(int $appliancesId): array
    {
        return array_values(array_filter(
            self::data()['alerts'],
            static fn (array $alert): bool => (int) $alert['appliances_id'] === $appliancesId
        ));
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi (droit du plugin, application lisible avec son entité).
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Appliance || $item->isNewItem() || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        $id = (int) $item->getID();
        $data = self::data();
        $alerts = self::alertsOf($id);
        $repository = $data['repositories'][$id] ?? null;
        $profile = ApplicationSecurity::getForAppliance($id);

        TemplateRenderer::getInstance()->display('@grcmanager/_code_security_tab.html.twig', [
            'summary'        => Rules::summarize($alerts),
            'alerts'         => array_map(self::decorate(...), array_slice($alerts, 0, self::MAX_TAB_ROWS)),
            'total_alerts'   => count($alerts),
            'repository'     => $repository !== null ? self::repositoryRows([$id => $repository])[0] : null,
            'repository_url' => (string) ($profile['repository_url'] ?? ''),
            'categories'     => self::categoryInfos(),
            'severities'     => self::severityInfos(),
            'providers'      => self::providerInfos(),
            'page_url'       => self::getSearchURL(),
        ]);

        return true;
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuve des mesures A.8.28 (secrets exposés, dépendances vulnérables) et A.8.29 (analyses
     * activées, alertes en retard). Null si la mesure n'est pas concernée, sans les droits ou sans
     * lecture.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.28', 'A.8.29'], true) || !self::canView()) {
            return null;
        }
        $data = self::data();
        if (!$data['readings']) {
            return null;
        }
        $lastRead = CodeSecurityConfig::lastRead();

        return [
            'code'    => $code,
            'summary' => self::summary($data),
            'date'    => $lastRead['date'] !== '' ? (string) Html::convDateTime($lastRead['date']) : '',
            'url'     => self::getSearchURL(),
        ];
    }
}
