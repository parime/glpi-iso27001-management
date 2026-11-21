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
 * Issue #835, lot 2 : lecture des résultats JSON de Maester (TestResults-*.json) et de ScubaGear
 * (ScubaResults_*.json), qui contrôlent la configuration d'Entra ID et de Microsoft 365. Le plugin
 * ne lance aucun outil et n'interroge pas le locataire : il lit le fichier produit par l'équipe.
 *
 * Sécurité : taille et profondeur du JSON limitées, nombre de contrôles borné. Seuls sont lus le
 * locataire (identifiant, nom, domaine), la date, la version de l'outil et, pour chaque contrôle,
 * son identifiant, son titre, sa gravité et son résultat. Ne sont jamais lus : la section « Raw »
 * de ScubaGear (configuration complète du locataire, comptes privilégiés), le compte, la machine,
 * le contexte de connexion, les erreurs et le détail des résultats de Maester.
 */
final class CloudPostureReport
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Profondeur maximale du JSON (au-delà, le fichier est refusé). */
    public const MAX_DEPTH = 128;

    /** Contrôles lus au plus. */
    public const MAX_CHECKS = 3000;

    public const ERROR_NOT_CLOUD = 'not_cloud';

    /** Produits de ScubaGear : Entra ID d'un côté, les services de Microsoft 365 de l'autre. */
    private const SCUBA_IDENTITY = ['aad', 'entraid'];

    /**
     * Décode un JSON borné ; null si le fichier est trop gros, trop profond ou invalide.
     *
     * @return array<mixed>|null
     */
    public static function decode(string $content, string &$error): ?array
    {
        if (trim($content) === '') {
            $error = PingCastleReport::ERROR_EMPTY;

            return null;
        }
        if (strlen($content) > self::MAX_BYTES) {
            $error = PingCastleReport::ERROR_TOO_LARGE;

            return null;
        }
        $content = AdAuditReports::toUtf8($content);
        if ($content === null) {
            $error = PingCastleReport::ERROR_INVALID;

            return null;
        }
        try {
            $data = json_decode($content, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            $error = PingCastleReport::ERROR_INVALID;

            return null;
        }
        if (!is_array($data) || array_is_list($data)) {
            $error = self::ERROR_NOT_CLOUD;

            return null;
        }

        return $data;
    }

    /**
     * Outil qui a produit le JSON décodé, ou '' s'il n'est ni Maester ni ScubaGear.
     *
     * @param array<mixed> $data
     */
    public static function detect(array $data): string
    {
        $meta = is_array($data['MetaData'] ?? null) ? $data['MetaData'] : [];
        if (
            (is_string($meta['Tool'] ?? null) && stripos($meta['Tool'], 'scuba') !== false)
            || (is_array($data['Results'] ?? null) && !array_is_list($data['Results']) && isset($data['Summary']))
        ) {
            return AdAuditRules::TOOL_SCUBAGEAR;
        }
        if (is_array($data['Tests'] ?? null) && array_is_list($data['Tests'])) {
            return AdAuditRules::TOOL_MAESTER;
        }

        return '';
    }

    /**
     * @return array<string, mixed> même forme que PingCastleReport::parse(), avec native_score
     *     (part des contrôles réussis), checks_total et checks_passed
     */
    public static function parse(string $content): array
    {
        $error = '';
        $data = self::decode($content, $error);
        if ($data === null) {
            return ['error' => $error] + AdAuditReports::emptyResult(AdAuditRules::TOOL_MAESTER);
        }

        return match (self::detect($data)) {
            AdAuditRules::TOOL_SCUBAGEAR => self::scubaGear($data),
            AdAuditRules::TOOL_MAESTER   => self::maester($data),
            default                      => ['error' => self::ERROR_NOT_CLOUD]
                + AdAuditReports::emptyResult(AdAuditRules::TOOL_MAESTER),
        };
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function maester(array $data): array
    {
        $result = AdAuditReports::emptyResult(AdAuditRules::TOOL_MAESTER);
        $tenantId = self::text($data['TenantId'] ?? '', 64);
        $result['domain'] = AdAuditRules::normalizeDomain($tenantId);
        $result['netbios'] = self::text($data['TenantName'] ?? '', 64);
        $result['report_date'] = PingCastleReport::date(self::text($data['ExecutedAt'] ?? '', 64));
        $result['engine_version'] = self::version($data['CurrentVersion'] ?? '');
        $failed = 0;
        $seen = [];
        foreach ($data['Tests'] as $test) {
            if (!is_array($test)) {
                continue;
            }
            if ($result['checks_total'] >= self::MAX_CHECKS) {
                $result['truncated'] = true;
                break;
            }
            $outcome = strtolower(self::text($test['Result'] ?? '', 32));
            if (!in_array($outcome, ['passed', 'failed', 'investigate'], true)) {
                // Ignoré, non lancé ou en erreur : ni réussi ni en échec.
                continue;
            }
            $result['checks_total']++;
            if ($outcome === 'passed') {
                $result['checks_passed']++;
                continue;
            }
            $failed++;
            $name = self::text($test['Name'] ?? '', 500);
            $id = self::text($test['Id'] ?? '', 128);
            $title = self::text($test['Title'] ?? '', 500);
            if ($id === '' && preg_match('/^([^\s:]{2,128})\s*:\s*(.*)$/u', $name, $m) === 1) {
                $id = $m[1];
                $title = $title !== '' ? $title : $m[2];
            }
            $id = $id !== '' ? mb_substr($id, 0, 128) : mb_substr($name, 0, 128);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $tags = is_array($test['Tag'] ?? null) ? array_filter($test['Tag'], 'is_string') : [];
            $block = self::text($test['Block'] ?? '', 128);
            $severity = self::text($test['Severity'] ?? '', 32);
            $result['rules'][] = [
                'rule_id'   => $id,
                'category'  => self::maesterCategory($id, $tags, $block),
                'model'     => mb_substr(trim($block . ($severity !== '' ? ' · ' . $severity : ''), ' ·'), 0, 128),
                // Sans gravité annoncée, un test en échec compte comme une gravité moyenne.
                'points'    => $severity === '' ? 10 : AdAuditRules::pointsForSeverity($severity),
                'rationale' => mb_substr($title !== '' ? $title : $name, 0, PingCastleReport::MAX_RATIONALE),
            ];
        }
        self::finish($result, $failed);

        return $result;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function scubaGear(array $data): array
    {
        $result = AdAuditReports::emptyResult(AdAuditRules::TOOL_SCUBAGEAR);
        $meta = is_array($data['MetaData'] ?? null) ? $data['MetaData'] : [];
        $domain = AdAuditRules::normalizeDomain(self::text($meta['DomainName'] ?? '', 255));
        $result['domain'] = $domain !== ''
            ? $domain
            : AdAuditRules::normalizeDomain(self::text($meta['TenantId'] ?? '', 64));
        $result['netbios'] = self::text($meta['DisplayName'] ?? '', 64);
        $result['report_date'] = PingCastleReport::date(self::text($meta['TimestampZulu'] ?? '', 64));
        $result['engine_version'] = self::text($meta['ToolVersion'] ?? '', 64);
        $failed = 0;
        $seen = [];
        $results = is_array($data['Results'] ?? null) ? $data['Results'] : [];
        foreach ($results as $product => $groups) {
            if (!is_array($groups)) {
                continue;
            }
            $category = in_array(strtolower((string) $product), self::SCUBA_IDENTITY, true)
                ? AdAuditRules::CATEGORY_IDENTITY
                : AdAuditRules::CATEGORY_CLOUD;
            foreach ($groups as $group) {
                $controls = is_array($group) && is_array($group['Controls'] ?? null) ? $group['Controls'] : [];
                $groupName = is_array($group) ? self::text($group['GroupName'] ?? '', 100) : '';
                foreach ($controls as $control) {
                    if (!is_array($control)) {
                        continue;
                    }
                    if ($result['checks_total'] >= self::MAX_CHECKS) {
                        $result['truncated'] = true;
                        break 3;
                    }
                    $outcome = strtolower(self::text($control['Result'] ?? '', 32));
                    if (!in_array($outcome, ['pass', 'fail', 'warning'], true)) {
                        // N/A, contrôle manuel, erreur ou omis.
                        continue;
                    }
                    $result['checks_total']++;
                    if ($outcome === 'pass') {
                        $result['checks_passed']++;
                        continue;
                    }
                    $failed++;
                    $id = self::text($control['Control ID'] ?? '', 128);
                    if ($id === '' || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $criticality = self::text($control['Criticality'] ?? '', 64);
                    // Le titre de ScubaGear porte un bandeau HTML (« Automated Check ») : retiré.
                    $requirement = self::text(
                        is_string($control['Requirement'] ?? null)
                            ? preg_replace('#<div[^>]*policy-indicators.*$#is', '', $control['Requirement'])
                            : '',
                        PingCastleReport::MAX_RATIONALE
                    );
                    $result['rules'][] = [
                        'rule_id'   => $id,
                        'category'  => $category,
                        'model'     => mb_substr(trim(strtoupper((string) $product) . ' · ' . $groupName . ' · '
                            . $criticality, ' ·'), 0, 128),
                        'points'    => self::scubaPoints($criticality, $outcome),
                        'rationale' => $requirement,
                    ];
                }
            }
        }
        self::finish($result, $failed);

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function finish(array &$result, int $failed): void
    {
        $rate = AdAuditRules::failureRate($failed, (int) $result['checks_total']);
        $result['scores']['global'] = $rate ?? 0;
        $result['native_score'] = $rate === null ? null : 100 - $rate;
        usort($result['rules'], static fn (array $a, array $b): int => $b['points'] <=> $a['points']);
    }

    /**
     * Gravité d'un contrôle ScubaGear : « Shall » (exigé) en échec est élevé, « Should »
     * (recommandé) moyen, le reste faible.
     */
    public static function scubaPoints(string $criticality, string $outcome): int
    {
        $criticality = strtolower($criticality);
        if (str_starts_with($criticality, 'shall') && $outcome === 'fail') {
            return 30;
        }

        return str_starts_with($criticality, 'should') || $outcome === 'fail' ? 10 : 5;
    }

    /**
     * @param array<mixed> $tags
     */
    private static function maesterCategory(string $id, array $tags, string $block): string
    {
        $haystack = strtolower($id . ' ' . $block . ' ' . implode(' ', $tags));
        $services = '/exo|exchange|teams|sharepoint|onedrive|defender|mdo|intune|purview|powerplatform/';
        if (preg_match($services, $haystack) === 1) {
            return AdAuditRules::CATEGORY_CLOUD;
        }

        return AdAuditRules::CATEGORY_IDENTITY;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return mb_substr($text, 0, $max);
    }

    /**
     * Version de Maester : chaîne, ou objet { Major, Minor, Build } sérialisé par PowerShell.
     */
    private static function version(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach (['Major', 'Minor', 'Build'] as $key) {
                if (is_int($value[$key] ?? null) && $value[$key] >= 0) {
                    $parts[] = (string) $value[$key];
                }
            }

            return implode('.', $parts);
        }

        return self::text($value, 64);
    }
}
