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
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\AdSecurityAuditTables as Tables;
use GlpiPlugin\Grcmanager\Services\Access\AdAuditRules as Rules;
use GlpiPlugin\Grcmanager\Services\Access\PingCastleReport;
use Html;
use Session;
use Ticket;

/**
 * Issue #835 : audit de sécurité de l'Active Directory, lot 1 (PingCastle).
 *
 * Le plugin ne lance aucun outil et n'interroge pas l'annuaire : il lit, en lecture seule, le
 * rapport XML que l'équipe a produit (PingCastleReport). Chaque import garde le score global, les
 * scores par catégorie et les règles déclenchées (identifiant, catégorie, points, constat), rangés
 * dans l'entité active ; l'historique par domaine montre l'évolution du score et les règles
 * apparues ou résolues d'un import au suivant.
 *
 * Chaque règle est rattachée aux mesures de l'Annexe A concernées (AdAuditRules::controls()) et,
 * sur clic seulement, proposée comme action (fiche de non-conformité pré-remplie, relue avant
 * l'enregistrement) ou comme ticket. Preuve sur les fiches des mesures A.5.15, A.5.17, A.5.18,
 * A.8.2 et A.8.5, KRI « score de sécurité de l'AD », carte du tableau de bord.
 *
 * Confidentialité : ces rapports décrivent les faiblesses du domaine. Registre sensible (droit
 * plugin_grcmanager_sensitive), limité aux entités de la session ; le rapport brut, nettoyé de ses
 * mots de passe et secrets, peut être archivé comme document restreint (RestrictedDocument), lisible
 * seulement avec ce droit dans l'entité de l'import.
 */
class AdSecurityAudit extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public const FK = 'plugin_grcmanager_adaudits_id';

    /** Imports listés au plus dans l'historique. */
    public const MAX_HISTORY = 100;

    public static function getTypeName($nb = 0)
    {
        return __('Active Directory security audit', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-sitemap';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/adsecurityaudit.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    /**
     * Import, suppression d'un import, proposition d'un ticket.
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

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function categoryInfos(): array
    {
        return [
            Rules::CATEGORY_STALE      => ['label' => __('Stale objects', 'grcmanager'), 'icon' => 'ti ti-user-off'],
            Rules::CATEGORY_PRIVILEGED => [
                'label' => __('Privileged accounts', 'grcmanager'),
                'icon'  => 'ti ti-crown',
            ],
            Rules::CATEGORY_TRUST      => ['label' => __('Trust relationships', 'grcmanager'), 'icon' => 'ti ti-link'],
            Rules::CATEGORY_ANOMALY    => ['label' => __('Anomalies', 'grcmanager'), 'icon' => 'ti ti-alert-triangle'],
            Rules::CATEGORY_OTHER      => ['label' => __('Other', 'grcmanager'), 'icon' => 'ti ti-dots'],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function levelInfos(): array
    {
        return [
            Rules::LEVEL_GOOD  => [
                'label' => __('Good', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
            ],
            Rules::LEVEL_WATCH => [
                'label' => __('To watch', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-shield-half',
            ],
            Rules::LEVEL_POOR  => [
                'label' => __('Worrying', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-shield-x',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function severityInfos(): array
    {
        return [
            Rules::SEVERITY_HIGH   => ['label' => __('High', 'grcmanager'), 'color' => 'danger'],
            Rules::SEVERITY_MEDIUM => ['label' => __('Medium', 'grcmanager'), 'color' => 'warning'],
            Rules::SEVERITY_LOW    => ['label' => __('Low', 'grcmanager'), 'color' => 'azure'],
            Rules::SEVERITY_INFO   => ['label' => __('Information', 'grcmanager'), 'color' => 'secondary'],
        ];
    }

    public static function importErrorMessage(string $code): string
    {
        return match ($code) {
            PingCastleReport::ERROR_EMPTY          => __('The file is empty.', 'grcmanager'),
            PingCastleReport::ERROR_TOO_LARGE      => __('The file is too large (20 MB at most).', 'grcmanager'),
            PingCastleReport::ERROR_DOCTYPE        => __(
                'The file declares a DOCTYPE or entities: it is refused for security reasons. A PingCastle '
                    . 'report never contains any.',
                'grcmanager'
            ),
            PingCastleReport::ERROR_NOT_PINGCASTLE => __(
                'This is not a PingCastle health check report: import the ad_hc_[domain].xml file.',
                'grcmanager'
            ),
            'no_domain'                            => __('The report gives no domain name.', 'grcmanager'),
            'duplicate'                            => __('This report has already been imported.', 'grcmanager'),
            'rights'                               => __('You do not have the right to import a report.', 'grcmanager'),
            default                                => __('The file could not be read.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * Import lisible par la session (droit, entité), ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function getAudit(int $auditId): ?array
    {
        global $DB;

        if ($auditId <= 0 || !self::canView()) {
            return null;
        }
        $row = $DB->request(['FROM' => Tables::AUDITS, 'WHERE' => ['id' => $auditId]])->current();
        if ($row === null || !Session::haveAccessToEntity((int) $row['entities_id'])) {
            return null;
        }

        return $row;
    }

    /**
     * Source d'un rapport archivé (RestrictedDocument) : droit du registre et entité de l'import.
     */
    public static function canReadAudit(int $auditId): bool
    {
        return self::getAudit($auditId) !== null;
    }

    /**
     * Imports des entités de la session, les plus récents d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function audits(): array
    {
        if (!self::canView()) {
            return [];
        }

        return self::fetchAudits(getEntitiesRestrictCriteria(Tables::AUDITS));
    }

    /**
     * @param array<mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function fetchAudits(array $where): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::AUDITS,
                'WHERE' => $where,
                'ORDER' => ['report_date DESC', 'id DESC'],
                'LIMIT' => self::MAX_HISTORY,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Dernier import de chaque domaine (par entité), sur les imports donnés (du plus récent au plus
     * ancien).
     *
     * @param list<array<string, mixed>> $audits
     * @return list<array<string, mixed>>
     */
    public static function latestByDomain(array $audits): array
    {
        $latest = [];
        foreach ($audits as $audit) {
            $key = $audit['entities_id'] . '|' . $audit['tool'] . '|' . $audit['domain'];
            $latest[$key] ??= $audit;
        }

        return array_values($latest);
    }

    /**
     * Import précédent du même domaine, outil et entité (plus ancien), ou null.
     *
     * @param array<string, mixed> $audit
     * @return array<string, mixed>|null
     */
    public static function previousAudit(array $audit): ?array
    {
        global $DB;

        $date = $audit['report_date'] ?? null;
        $older = $date === null
            ? ['id' => ['<', (int) $audit['id']]]
            : ['OR' => [
                ['report_date' => ['<', $date]],
                ['report_date' => $date, 'id' => ['<', (int) $audit['id']]],
            ]];
        $row = $DB->request([
            'FROM'  => Tables::AUDITS,
            'WHERE' => [
                'entities_id' => (int) $audit['entities_id'],
                'tool'        => (string) $audit['tool'],
                'domain'      => (string) $audit['domain'],
                'NOT'         => ['id' => (int) $audit['id']],
            ] + [$older],
            'ORDER' => ['report_date DESC', 'id DESC'],
            'LIMIT' => 1,
        ])->current();

        return $row ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rulesOf(int $auditId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Tables::RULES,
                'WHERE' => [self::FK => $auditId],
                'ORDER' => ['points DESC', 'rule_id'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Pire score global (le plus élevé) des derniers imports de chaque domaine d'un périmètre
     * d'entités (KRI, sans session) ; null sans import.
     *
     * @param list<int> $perimeter
     */
    public static function worstScore(array $perimeter): ?float
    {
        if ($perimeter === []) {
            return null;
        }
        $latest = self::latestByDomain(self::fetchAudits(['entities_id' => $perimeter]));
        if ($latest === []) {
            return null;
        }

        return (float) max(array_map(static fn (array $a): int => (int) $a['global_score'], $latest));
    }

    // ------------------------------------------------------------------ Import.

    /**
     * Importe un rapport PingCastle dans l'entité active. Le même fichier n'est importé qu'une fois
     * par entité. Les liens d'une règle déjà présente dans l'import précédent du domaine (action,
     * ticket) sont repris.
     *
     * @return array{id: int, error: string, rules: int, secrets: bool, archived: bool, truncated: bool}
     */
    public static function import(string $raw, string $fileName, bool $archive): array
    {
        global $DB;

        $result = [
            'id' => 0, 'error' => '', 'rules' => 0, 'secrets' => false, 'archived' => false, 'truncated' => false,
        ];
        if (!self::canManage()) {
            return ['error' => 'rights'] + $result;
        }
        $report = PingCastleReport::parse($raw);
        if ($report['error'] !== '') {
            return ['error' => $report['error']] + $result;
        }
        $domain = Rules::normalizeDomain($report['domain']);
        if ($domain === '') {
            return ['error' => 'no_domain'] + $result;
        }
        $entity = (int) Session::getActiveEntity();
        $hash = hash('sha256', $raw);
        if (countElementsInTable(Tables::AUDITS, ['entities_id' => $entity, 'file_sha256' => $hash]) > 0) {
            return ['error' => 'duplicate'] + $result;
        }

        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $DB->insert(Tables::AUDITS, [
            'entities_id'      => $entity,
            'tool'             => $report['tool'],
            'domain'           => $domain,
            'netbios'          => mb_substr($report['netbios'], 0, 64),
            'report_date'      => $report['report_date'] ?? $now,
            'engine_version'   => $report['engine_version'],
            'global_score'     => $report['scores']['global'],
            'stale_score'      => $report['scores']['stale'],
            'privileged_score' => $report['scores']['privileged'],
            'trust_score'      => $report['scores']['trust'],
            'anomaly_score'    => $report['scores']['anomaly'],
            'maturity_level'   => $report['maturity_level'],
            'rule_count'       => count($report['rules']),
            'file_name'        => mb_substr(basename($fileName), 0, 255),
            'file_sha256'      => $hash,
            'secrets_found'    => $report['secrets'] ? 1 : 0,
            'documents_id'     => 0,
            'users_id'         => (int) Session::getLoginUserID(),
            'date_import'      => $now,
        ]);
        $auditId = (int) $DB->insertId();
        if ($auditId <= 0) {
            return ['error' => 'invalid'] + $result;
        }
        $audit = $DB->request(['FROM' => Tables::AUDITS, 'WHERE' => ['id' => $auditId]])->current() ?? [];

        // Liens repris de l'import précédent du domaine, par identifiant de règle.
        $carried = [];
        $previous = self::previousAudit($audit);
        if ($previous !== null) {
            foreach (self::rulesOf((int) $previous['id']) as $rule) {
                $carried[(string) $rule['rule_id']] = [
                    'plugin_grcmanager_nonconformities_id' => (int) $rule['plugin_grcmanager_nonconformities_id'],
                    'tickets_id'                           => (int) $rule['tickets_id'],
                ];
            }
        }
        foreach ($report['rules'] as $rule) {
            $DB->insert(Tables::RULES, [
                self::FK    => $auditId,
                'rule_id'   => $rule['rule_id'],
                'category'  => $rule['category'],
                'model'     => $rule['model'],
                'points'    => $rule['points'],
                'rationale' => $rule['rationale'],
            ] + ($carried[$rule['rule_id']] ?? []));
        }

        $archived = false;
        if ($archive) {
            $archived = self::archive($auditId, $raw, $domain, $entity, (string) ($audit['report_date'] ?? $now));
        }
        AuditLog::record('import', self::class, $auditId, 0, 'success', [
            'tool'    => $report['tool'],
            'domain'  => $domain,
            'score'   => $report['scores']['global'],
            'rules'   => count($report['rules']),
            'secrets' => $report['secrets'],
        ]);

        return [
            'id'        => $auditId,
            'error'     => '',
            'rules'     => count($report['rules']),
            'secrets'   => $report['secrets'],
            'archived'  => $archived,
            'truncated' => $report['truncated'],
        ];
    }

    /**
     * Archive le rapport nettoyé de ses secrets comme document restreint de l'import.
     */
    private static function archive(int $auditId, string $raw, string $domain, int $entity, string $date): bool
    {
        global $DB;

        $clean = PingCastleReport::sanitize($raw);
        if ($clean === null) {
            return false;
        }
        $fileName = sprintf(
            'pingcastle-%s-%s.xml',
            preg_replace('/[^a-z0-9.-]/', '-', $domain),
            date('Ymd-His', (int) strtotime($date))
        );
        $path = GLPI_TMP_DIR . '/' . $fileName;
        if (file_put_contents($path, $clean) === false) {
            return false;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                __('PingCastle report - %1$s (%2$s)', 'grcmanager'),
                $domain,
                Html::convDateTime($date)
            ),
            'entities_id'   => $entity,
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if (is_file($path)) {
            // Fichier temporaire écrit ci-dessus dans GLPI_TMP_DIR, jamais un chemin reçu.
            unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
        if ($documentsId <= 0) {
            return false;
        }
        RestrictedDocument::registerFor($documentsId, self::class, $auditId);
        $DB->update(Tables::AUDITS, ['documents_id' => $documentsId], ['id' => $auditId]);

        return true;
    }

    /**
     * Supprime un import, ses règles et son rapport archivé. Les actions et tickets créés restent.
     */
    public static function clear(int $auditId): bool
    {
        global $DB;

        $audit = self::canManage() ? self::getAudit($auditId) : null;
        if ($audit === null) {
            return false;
        }
        $DB->delete(Tables::RULES, [self::FK => $auditId]);
        $DB->delete(Tables::AUDITS, ['id' => $auditId]);
        $documentsId = (int) $audit['documents_id'];
        $document = new Document();
        if ($documentsId > 0 && $document->getFromDB($documentsId)) {
            $document->delete(['id' => $documentsId], true);
        }
        AuditLog::record('purge', self::class, $auditId, 0, 'success', ['domain' => (string) $audit['domain']]);

        return true;
    }

    // ------------------------------------------------------------------ Action, ticket.

    /**
     * Règle lisible par la session, avec son import.
     *
     * @return array{rule: array<string, mixed>, audit: array<string, mixed>}|null
     */
    public static function getRule(int $ruleId): ?array
    {
        global $DB;

        if ($ruleId <= 0) {
            return null;
        }
        $rule = $DB->request(['FROM' => Tables::RULES, 'WHERE' => ['id' => $ruleId]])->current();
        $audit = $rule === null ? null : self::getAudit((int) $rule[self::FK]);

        return $rule === null || $audit === null ? null : ['rule' => $rule, 'audit' => $audit];
    }

    /**
     * Fiche d'action (non-conformité) pré-remplie depuis une règle, relue par l'utilisateur.
     *
     * @return array<string, mixed>|null
     */
    public static function findingDraft(int $ruleId): ?array
    {
        $found = self::getRule($ruleId);
        if ($found === null || (int) $found['rule']['plugin_grcmanager_nonconformities_id'] > 0) {
            return null;
        }
        ['rule' => $rule, 'audit' => $audit] = $found;
        $controls = Rules::controls((string) $rule['category'], (string) $rule['rule_id']);

        return Rules::findingPreset((int) $rule['points']) + [
            'rule_row_id'           => $ruleId,
            'rule_title'            => (string) $rule['rule_id'],
            'entities_id'           => (int) $audit['entities_id'],
            'is_recursive'          => 0,
            'requirement_reference' => implode(', ', $controls),
            'title'                 => mb_substr(sprintf(
                __('Active Directory %1$s: %2$s', 'grcmanager'),
                (string) $audit['domain'],
                (string) $rule['rule_id']
            ), 0, 255),
            'description'           => self::ruleSummary($rule, $audit, $controls),
        ];
    }

    /**
     * Texte de la règle pour une action ou un ticket (sans HTML).
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $audit
     * @param list<string> $controls
     */
    private static function ruleSummary(array $rule, array $audit, array $controls): string
    {
        $categories = self::categoryInfos();
        $lines = [
            sprintf(__('Domain: %s', 'grcmanager'), (string) $audit['domain']),
            sprintf(
                __('Report: %1$s of %2$s (score %3$d/100)', 'grcmanager'),
                'PingCastle',
                Html::convDateTime((string) $audit['report_date']),
                (int) $audit['global_score']
            ),
            sprintf(__('Rule: %s', 'grcmanager'), (string) $rule['rule_id']),
            sprintf(
                __('Category: %s', 'grcmanager'),
                $categories[(string) $rule['category']]['label'] ?? (string) $rule['category']
            ),
            sprintf(__('Points: %d', 'grcmanager'), (int) $rule['points']),
            sprintf(__('ISO/IEC 27001 controls: %s', 'grcmanager'), implode(', ', $controls)),
        ];
        if (trim((string) $rule['rationale']) !== '') {
            $lines[] = '';
            $lines[] = (string) $rule['rationale'];
        }

        return implode("\n", $lines);
    }

    /**
     * Après l'enregistrement de l'action ouverte depuis une règle : la règle y est liée.
     */
    public static function attachFinding(int $ruleId, Nonconformity $finding): bool
    {
        global $DB;

        $found = self::getRule($ruleId);
        if (
            $found === null || !self::canManage()
            || (int) $found['rule']['plugin_grcmanager_nonconformities_id'] > 0
            || (int) ($finding->fields['entities_id'] ?? -1) !== (int) $found['audit']['entities_id']
        ) {
            return false;
        }

        return (bool) $DB->update(
            Tables::RULES,
            ['plugin_grcmanager_nonconformities_id' => $finding->getID()],
            ['id' => $ruleId]
        );
    }

    public static function unlinkFinding(int $findingId): void
    {
        global $DB;

        $DB->update(Tables::RULES, ['plugin_grcmanager_nonconformities_id' => 0], [
            'plugin_grcmanager_nonconformities_id' => $findingId,
        ]);
    }

    /**
     * Ticket proposé sur clic pour une règle (jamais automatiquement), lié à la règle.
     */
    public static function createTicket(int $ruleId): int
    {
        global $DB;

        $found = self::getRule($ruleId);
        if (
            $found === null || !self::canManage() || !Session::haveRight(Ticket::$rightname, CREATE)
            || (int) $found['rule']['tickets_id'] > 0
        ) {
            return 0;
        }
        ['rule' => $rule, 'audit' => $audit] = $found;
        $controls = Rules::controls((string) $rule['category'], (string) $rule['rule_id']);
        $content = TemplateRenderer::getInstance()->render('@grcmanager/ad_security_audit_ticket.html.twig', [
            'lines' => explode("\n", self::ruleSummary($rule, $audit, $controls)),
        ]);
        $ticketId = (int) (new Ticket())->add([
            'name'        => mb_substr(sprintf(
                __('Active Directory %1$s: %2$s', 'grcmanager'),
                (string) $audit['domain'],
                (string) $rule['rule_id']
            ), 0, 255),
            'content'     => $content,
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => (int) $audit['entities_id'],
        ]);
        if ($ticketId > 0) {
            $DB->update(Tables::RULES, ['tickets_id' => $ticketId], ['id' => $ruleId]);
        }

        return $ticketId;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * Détail d'un import : règles avec leurs mesures, apparues depuis l'import précédent, règles
     * résolues.
     *
     * @param array<string, mixed> $audit
     * @return array<string, mixed>
     */
    public static function detail(array $audit): array
    {
        global $DB;

        $previous = self::previousAudit($audit);
        $rules = self::rulesOf((int) $audit['id']);
        $previousRules = $previous === null ? [] : self::rulesOf((int) $previous['id']);
        $ids = static fn (array $list): array => array_map(
            static fn (array $r): string => (string) $r['rule_id'],
            $list
        );
        $diff = Rules::diff($ids($rules), $previous === null ? null : $ids($previousRules));
        $findingIds = array_values(array_filter(array_map(
            static fn (array $r): int => (int) $r['plugin_grcmanager_nonconformities_id'],
            $rules
        )));
        $findings = [];
        if ($findingIds !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'title'],
                    'FROM'   => Nonconformity::getTable(),
                    'WHERE'  => ['id' => $findingIds],
                ]) as $row
            ) {
                $findings[(int) $row['id']] = (string) $row['title'];
            }
        }
        $rows = [];
        foreach ($rules as $rule) {
            $findingId = (int) $rule['plugin_grcmanager_nonconformities_id'];
            $ticketId = (int) $rule['tickets_id'];
            $rows[] = [
                'id'          => (int) $rule['id'],
                'rule_id'     => (string) $rule['rule_id'],
                'category'    => (string) $rule['category'],
                'model'       => (string) $rule['model'],
                'points'      => (int) $rule['points'],
                'severity'    => Rules::severity((int) $rule['points']),
                'rationale'   => (string) $rule['rationale'],
                'controls'    => Rules::controls((string) $rule['category'], (string) $rule['rule_id']),
                'is_new'      => in_array((string) $rule['rule_id'], $diff['new'], true),
                'finding_id'  => isset($findings[$findingId]) ? $findingId : 0,
                'finding'     => $findings[$findingId] ?? '',
                'finding_url' => isset($findings[$findingId]) ? Nonconformity::getFormURLWithID($findingId) : '',
                'ticket_id'   => $ticketId,
                'ticket_url'  => $ticketId > 0 ? Ticket::getFormURLWithID($ticketId) : '',
            ];
        }
        $resolved = array_values(array_filter(
            $previousRules,
            static fn (array $r): bool => in_array((string) $r['rule_id'], $diff['resolved'], true)
        ));
        $document = null;
        $documentsId = (int) $audit['documents_id'];
        $doc = new Document();
        if ($documentsId > 0 && $doc->getFromDB($documentsId)) {
            $document = ['name' => (string) $doc->fields['name'], 'url' => Document::getFormURLWithID($documentsId)];
        }

        return [
            'audit'    => self::present($audit, $previous),
            'previous' => $previous === null ? null : self::present($previous, null),
            'rows'     => $rows,
            'new'      => count($diff['new']),
            'resolved' => array_map(static fn (array $r): array => [
                'rule_id'  => (string) $r['rule_id'],
                'category' => (string) $r['category'],
                'points'   => (int) $r['points'],
            ], $resolved),
            'document' => $document,
        ];
    }

    /**
     * @param array<string, mixed> $audit
     * @param array<string, mixed>|null $previous
     * @return array<string, mixed>
     */
    private static function present(array $audit, ?array $previous): array
    {
        $score = (int) $audit['global_score'];
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $scores = [
            Rules::CATEGORY_STALE      => $int($audit['stale_score']),
            Rules::CATEGORY_PRIVILEGED => $int($audit['privileged_score']),
            Rules::CATEGORY_TRUST      => $int($audit['trust_score']),
            Rules::CATEGORY_ANOMALY    => $int($audit['anomaly_score']),
        ];

        return [
            'id'          => (int) $audit['id'],
            'domain'      => (string) $audit['domain'],
            'netbios'     => (string) $audit['netbios'],
            'tool'        => (string) $audit['tool'],
            'date'        => (string) Html::convDateTime((string) $audit['report_date']),
            'imported'    => (string) Html::convDateTime((string) $audit['date_import']),
            'engine'      => (string) $audit['engine_version'],
            'score'       => $score,
            'level'       => Rules::scoreLevel($score),
            'trend'       => Rules::trend($score, $previous === null ? null : (int) $previous['global_score']),
            'scores'      => $scores,
            'levels'      => array_map(static fn (?int $v): ?string => Rules::scoreLevel($v), $scores),
            'maturity'    => $int($audit['maturity_level']),
            'rule_count'  => (int) $audit['rule_count'],
            'secrets'     => (bool) $audit['secrets_found'],
            'has_report'  => (int) $audit['documents_id'] > 0,
            'entity'      => (string) Dropdown::getDropdownName('glpi_entities', (int) $audit['entities_id']),
            'url'         => self::getSearchURL() . '?id=' . (int) $audit['id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(int $selectedId = 0): array
    {
        $audits = self::audits();
        $latest = self::latestByDomain($audits);
        $selected = null;
        foreach ($audits as $audit) {
            if ((int) $audit['id'] === $selectedId) {
                $selected = $audit;
            }
        }
        $selected ??= $audits[0] ?? null;
        $domains = [];
        foreach ($latest as $audit) {
            $domains[] = self::present($audit, self::previousAudit($audit));
        }
        $history = [];
        foreach ($audits as $audit) {
            $history[] = self::present($audit, self::previousAudit($audit));
        }

        return [
            'domains'         => $domains,
            'history'         => $history,
            'detail'          => $selected === null ? null : self::detail($selected),
            'categories'      => self::categoryInfos(),
            'levels'          => self::levelInfos(),
            'severities'      => self::severityInfos(),
            'can_manage'      => self::canManage(),
            'can_ticket'      => self::canManage() && (bool) Session::haveRight(Ticket::$rightname, CREATE),
            'can_finding'     => Nonconformity::canCreate(),
            'finding_url'     => Nonconformity::getFormURL(),
            'action'          => self::getSearchURL(),
            'max_mb'          => (int) (PingCastleReport::MAX_BYTES / 1024 / 1024),
            'example_url'     => self::getSearchURL() . '?example=1',
            'hygiene_url'     => AccountHygiene::getSearchURL(),
            'review_url'      => AccessReview::getSearchURL(),
        ];
    }

    public static function showPage(int $selectedId = 0): void
    {
        TemplateRenderer::getInstance()->display(
            '@grcmanager/ad_security_audit.html.twig',
            self::pageData($selectedId)
        );
    }

    // ------------------------------------------------------------------ Preuves, tableau de bord.

    /**
     * Preuve des mesures A.5.15, A.5.17, A.5.18, A.8.2 et A.8.5 : dernier score de chaque domaine
     * et règles déclenchées qui concernent la mesure.
     *
     * @return array{domains: list<array{domain: string, score: int, level: ?string, date: string,
     *     rules: int, url: string}>, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, Rules::EVIDENCE_CONTROLS, true) || !self::canView()) {
            return null;
        }
        $domains = [];
        foreach (self::latestByDomain(self::audits()) as $audit) {
            $count = 0;
            foreach (self::rulesOf((int) $audit['id']) as $rule) {
                if (in_array($code, Rules::controls((string) $rule['category'], (string) $rule['rule_id']), true)) {
                    $count++;
                }
            }
            $domains[] = [
                'domain' => (string) $audit['domain'],
                'score'  => (int) $audit['global_score'],
                'level'  => Rules::scoreLevel((int) $audit['global_score']),
                'date'   => (string) Html::convDateTime((string) $audit['report_date']),
                'rules'  => $count,
                'url'    => self::getSearchURL() . '?id=' . (int) $audit['id'],
            ];
        }

        return $domains === [] ? null : ['domains' => $domains, 'url' => self::getSearchURL()];
    }

    /**
     * Carte du tableau de bord : pire score de sécurité de l'AD (0 le meilleur, 100 le pire).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardCard(array $params = []): array
    {
        $latest = self::canView() ? self::latestByDomain(self::audits()) : [];
        $score = $latest === [] ? 0 : max(array_map(static fn (array $a): int => (int) $a['global_score'], $latest));

        return [
            'number' => $score,
            'url'    => self::getSearchURL(),
            'label'  => $params['label'] ?? __('Active Directory security score (PingCastle, 0 is best)', 'grcmanager'),
            'icon'   => self::getIcon(),
        ];
    }

    /**
     * Préparation à la certification : constat, null sans droit ou sans import.
     */
    public static function readinessFact(): ?string
    {
        if (!self::canView()) {
            return null;
        }
        $latest = self::latestByDomain(self::audits());
        if ($latest === []) {
            return null;
        }
        $worst = $latest[0];
        foreach ($latest as $audit) {
            if ((int) $audit['global_score'] > (int) $worst['global_score']) {
                $worst = $audit;
            }
        }

        return sprintf(
            __('Active Directory audit: score %1$d/100 for %2$s (%3$s), %4$d rule(s) triggered', 'grcmanager'),
            (int) $worst['global_score'],
            (string) $worst['domain'],
            (string) Html::convDate((string) $worst['report_date']),
            (int) $worst['rule_count']
        );
    }
}
