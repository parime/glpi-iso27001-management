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

namespace GlpiPlugin\Grcmanager\Services\Access;

/**
 * Issue #835, lot 2 : lecture de l'export CSV des indicateurs de sécurité de Purple Knight
 * (Semperis) : indicateurs d'exposition (IOE) et de compromission (IOC) de l'Active Directory, et
 * d'Entra ID ou d'Okta selon l'analyse. Le plugin ne lance pas l'outil : il lit le fichier exporté.
 *
 * Les colonnes sont reconnues par leur en-tête (anglais, séparateur virgule, point-virgule ou
 * tabulation), dans n'importe quel ordre : seul le nom de l'indicateur est obligatoire. Sont lus
 * le domaine, la catégorie, la gravité, le type (IOE ou IOC), le score de l'indicateur (100 = réussi),
 * son état et un constat court. Les colonnes de détail (objets concernés) ne sont jamais reprises.
 *
 * Sécurité : taille, nombre de lignes et longueur de cellule limités ; un constat qui ressemble à un
 * mot de passe ou un secret n'est pas gardé (sa présence est signalée).
 */
final class PurpleKnightReport
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Indicateurs lus au plus. */
    public const MAX_ROWS = 2000;

    /** Longueur gardée d'une cellule. */
    public const MAX_CELL = 1000;

    public const ERROR_NOT_PURPLEKNIGHT = 'not_purpleknight';

    /** En-têtes reconnus pour chaque champ (minuscules, sans espaces ni ponctuation). */
    private const HEADERS = [
        'name'        => ['indicatorname', 'indicator', 'securityindicator', 'name', 'title', 'checkname'],
        'id'          => ['indicatorid', 'id', 'checkid'],
        'category'    => ['category', 'categoryname', 'securitycategory'],
        'severity'    => ['severity', 'severitylevel', 'criticality'],
        'type'        => ['type', 'indicatortype', 'ioctype'],
        'score'       => ['score', 'indicatorscore', 'resultscore'],
        'status'      => ['status', 'result', 'state', 'outcome'],
        'message'     => ['resultmessage', 'message', 'findings', 'finding', 'summary'],
        'description' => ['description', 'indicatordescription'],
        'domain'      => ['domain', 'domainname', 'forest', 'forestname', 'tenant', 'environment'],
        'date'        => ['scandate', 'date', 'scantime', 'timestamp', 'executedat'],
        'version'     => ['version', 'toolversion', 'purpleknightversion'],
    ];

    /** États d'un indicateur réussi ou non évalué. */
    private const PASSED = ['pass', 'passed', 'success', 'ok', 'compliant', 'réussi', 'reussi'];

    private const SKIPPED = [
        'notrelevant', 'notapplicable', 'na', 'skipped', 'error', 'notrun', 'unknown', 'disabled',
        'nonapplicable', 'erreur',
    ];

    /** Un constat qui contient un mot de passe ou un secret (« password: … », « secret=… »). */
    private const SECRET_TEXT = '/(pass(word|wd)?|pwd|secret|hash|token)\s*[:=]\s*\S/i';

    /**
     * @return array{error: string, tool: string, domain: string, netbios: string, report_date: ?string,
     *     engine_version: string, maturity_level: ?int, scores: array{global: int, stale: ?int,
     *     privileged: ?int, trust: ?int, anomaly: ?int}, native_score: ?int, checks_total: int,
     *     checks_passed: int, rules: list<array{rule_id: string, category: string, model: string,
     *     points: int, rationale: string}>, secrets: bool, truncated: bool}
     */
    public static function parse(string $content): array
    {
        $result = AdAuditReports::emptyResult(AdAuditRules::TOOL_PURPLEKNIGHT);
        if (trim($content) === '') {
            return ['error' => PingCastleReport::ERROR_EMPTY] + $result;
        }
        if (strlen($content) > self::MAX_BYTES) {
            return ['error' => PingCastleReport::ERROR_TOO_LARGE] + $result;
        }
        $content = AdAuditReports::toUtf8($content);
        if ($content === null || str_contains($content, "\0")) {
            return ['error' => PingCastleReport::ERROR_INVALID] + $result;
        }

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return ['error' => PingCastleReport::ERROR_INVALID] + $result;
        }
        fwrite($handle, $content);
        rewind($handle);
        $firstLine = strtok($content, "\n");
        $delimiter = self::delimiter($firstLine === false ? '' : $firstLine);
        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        $columns = is_array($header) ? self::columns($header) : [];
        if (!isset($columns['name'])) {
            fclose($handle);

            return ['error' => self::ERROR_NOT_PURPLEKNIGHT] + $result;
        }

        $weighted = 0;
        $weights = 0;
        $domains = [];
        $seen = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $cell = static fn (string $field): string => isset($columns[$field])
                ? self::clean((string) ($row[$columns[$field]] ?? ''), $field === 'name' ? 128 : self::MAX_CELL)
                : '';
            $name = $cell('name');
            if ($name === '') {
                continue;
            }
            if ($result['checks_total'] >= self::MAX_ROWS) {
                $result['truncated'] = true;
                break;
            }
            $status = strtolower((string) preg_replace('/[^a-zA-Z]/', '', $cell('status')));
            if (in_array($status, self::SKIPPED, true)) {
                continue;
            }
            $score = self::score($cell('score'));
            $passed = in_array($status, self::PASSED, true) || ($status === '' && $score === 100);
            $failed = !$passed && ($status !== '' || ($score !== null && $score < 100));
            if (!$passed && !$failed) {
                // Ni état ni score : l'indicateur n'est pas exploitable.
                continue;
            }
            $severity = $cell('severity');
            $points = AdAuditRules::pointsForSeverity($severity);
            $weight = max(1, intdiv($points, 10));
            if ($score !== null) {
                $weighted += $weight * $score;
            } else {
                $weighted += $weight * ($passed ? 100 : 0);
            }
            $weights += $weight;
            $result['checks_total']++;
            $domain = AdAuditRules::normalizeDomain($cell('domain'));
            if ($domain !== '') {
                $domains[$domain] = ($domains[$domain] ?? 0) + 1;
            }
            if ($result['report_date'] === null && $cell('date') !== '') {
                $result['report_date'] = PingCastleReport::date($cell('date'));
            }
            if ($result['engine_version'] === '' && $cell('version') !== '') {
                $result['engine_version'] = mb_substr($cell('version'), 0, 64);
            }
            if ($passed) {
                $result['checks_passed']++;
                continue;
            }

            $ruleId = $cell('id') !== '' ? mb_substr($cell('id'), 0, 128) : $name;
            if (isset($seen[$ruleId])) {
                continue;
            }
            $seen[$ruleId] = true;
            $finding = $cell('message') !== '' ? $cell('message') : $cell('description');
            if (preg_match(self::SECRET_TEXT, $finding) === 1) {
                $result['secrets'] = true;
                $finding = $cell('description');
                if (preg_match(self::SECRET_TEXT, $finding) === 1) {
                    $finding = '';
                }
            }
            $type = self::type($cell('type'));
            $result['rules'][] = [
                'rule_id'   => $ruleId,
                'category'  => AdAuditRules::categoryFromLabel($cell('category')),
                'model'     => mb_substr(trim(($type !== '' ? $type . ' · ' : '') . $severity, ' ·'), 0, 128),
                'points'    => $points,
                'rationale' => $ruleId === $name ? $finding : trim($name . '. ' . $finding, ' .'),
            ];
        }
        fclose($handle);

        if ($weights > 0) {
            $native = AdAuditRules::clampScore((int) round($weighted / $weights));
            $result['native_score'] = $native;
            $result['scores']['global'] = AdAuditRules::riskFromSecurityScore($native);
        }
        if ($domains !== []) {
            arsort($domains);
            $result['domain'] = (string) array_key_first($domains);
        }
        usort($result['rules'], static fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        return $result;
    }

    /**
     * Séparateur le plus fréquent de la ligne d'en-tête.
     */
    private static function delimiter(string $line): string
    {
        $best = ',';
        $count = 0;
        foreach ([',', ';', "\t"] as $candidate) {
            $n = substr_count($line, $candidate);
            if ($n > $count) {
                $best = $candidate;
                $count = $n;
            }
        }

        return $best;
    }

    /**
     * @param array<int, string|null> $header
     * @return array<string, int>
     */
    private static function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $label) {
            $key = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $label));
            foreach (self::HEADERS as $field => $names) {
                if (!isset($columns[$field]) && in_array($key, $names, true)) {
                    $columns[$field] = (int) $index;
                    break;
                }
            }
        }

        return $columns;
    }

    private static function clean(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return mb_substr($value, 0, $max);
    }

    /**
     * Score d'un indicateur (0 à 100, 100 = réussi), « 85 », « 85% » ou « 85/100 » ; null sinon.
     */
    private static function score(string $value): ?int
    {
        if (preg_match('/^(\d{1,3})(?:[.,]\d+)?\s*(?:%|\/\s*100)?$/', trim($value), $m) !== 1) {
            return null;
        }

        return AdAuditRules::clampScore((int) $m[1]);
    }

    private static function type(string $value): string
    {
        $key = strtolower((string) preg_replace('/[^a-zA-Z]/', '', $value));

        return match (true) {
            $key === 'ioc' || str_contains($key, 'compromise') => 'IOC',
            $key === 'ioe' || str_contains($key, 'exposure')   => 'IOE',
            default                                             => '',
        };
    }
}
