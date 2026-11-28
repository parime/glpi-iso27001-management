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

namespace GlpiPlugin\Grcmanager\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Control\WazuhEvidence;

/**
 * Issue #1185 : regroupement des alertes Wazuh à revoir avant d'en faire des incidents de sécurité.
 * Règles pures ; la lecture des tables du plugin Wazuh est dans WazuhEvidenceProvider, la création
 * des incidents dans WazuhAlertImporter.
 *
 * Une alerte répétée (même règle sur le même ordinateur) forme un seul groupe : un seul incident,
 * auquel les répétitions suivantes s'ajoutent tant qu'il reste ouvert.
 */
final class WazuhAlertGrouping
{
    /** Préfixe des identifiants d'alerte gardés contre les doublons (table des alertes de l'import EDR). */
    public const ALERT_PREFIX = 'wazuh:';
    /** Repère du groupe dans la même table : retrouve l'incident ouvert d'une alerte répétée. */
    public const GROUP_PREFIX = 'wazuh-group:';

    /** Niveaux minimaux proposés dans la revue (niveau de règle Wazuh, 0 à 15). */
    public const LEVELS = [0, 7, 12];
    public const DEFAULT_LEVEL = 7;

    /** Groupes affichés au plus, et groupes importés au plus en une fois. */
    public const MAX_GROUPS = 200;

    /** Alertes détaillées au plus dans la description d'un incident. */
    public const MAX_DETAILED = 20;

    public static function alertId(string $key): string
    {
        return self::ALERT_PREFIX . mb_substr(trim($key), 0, 240);
    }

    public static function groupMarker(string $groupKey): string
    {
        return self::GROUP_PREFIX . $groupKey;
    }

    /**
     * Identifiant de règle sûr pour la clé du groupe : celui de Wazuh s'il est simple, sinon une
     * empreinte de la description, sinon « - ».
     */
    public static function ruleKey(string $ruleId, string $description): string
    {
        $ruleId = trim($ruleId);
        if (preg_match('/^[A-Za-z0-9_.]{1,64}$/', $ruleId) === 1) {
            return $ruleId;
        }

        return trim($description) !== '' ? 'd' . substr(sha1(trim($description)), 0, 12) : '-';
    }

    public static function groupKey(int $computerId, string $ruleId, string $description): string
    {
        return $computerId . ':' . self::ruleKey($ruleId, $description);
    }

    /** Clé de groupe reçue d'un formulaire : forme exacte, rien d'autre. */
    public static function isGroupKey(string $key): bool
    {
        return preg_match('/^[1-9]\d{0,9}:(?:-|[A-Za-z0-9_.]{1,64})$/', $key) === 1;
    }

    /** Ordinateur d'une clé de groupe valide. */
    public static function computerOf(string $key): int
    {
        return (int) strtok($key, ':');
    }

    /** Niveau minimal reçu d'un formulaire, ramené aux valeurs proposées. */
    public static function level(mixed $value): int
    {
        $level = is_numeric($value) ? (int) $value : self::DEFAULT_LEVEL;

        return in_array($level, self::LEVELS, true) ? $level : self::DEFAULT_LEVEL;
    }

    /**
     * Regroupe les alertes par ordinateur et règle. Les alertes déjà importées (`$imported`, par
     * identifiant ALERT_PREFIX + clé Wazuh) ne comptent plus ; un groupe sans alerte nouvelle ou
     * sous le niveau minimal (niveau le plus haut du groupe) est écarté. Tri : niveau décroissant,
     * puis dernière alerte la plus récente.
     *
     * @param list<array{id: int, key: string, computers_id: int, agent: string, date: string,
     *     level: int|null, description: string, rule_id: string, techniques: list<string>}> $alerts
     * @param array<string, true> $imported
     * @return list<array{key: string, computers_id: int, agent: string, rule_id: string,
     *     description: string, level: int|null, severity: string, incident_severity: string, count: int,
     *     repeated: bool, first: string, last: string, techniques: list<string>, alerts: list<array{id: int,
     *     key: string, date: string, level: int|null}>}>
     */
    public static function group(array $alerts, array $imported, int $minLevel): array
    {
        $groups = [];
        $seen = [];
        foreach ($alerts as $alert) {
            $alertKey = trim($alert['key']);
            if ($alert['computers_id'] <= 0 || $alertKey === '') {
                continue;
            }
            $key = self::groupKey($alert['computers_id'], $alert['rule_id'], $alert['description']);
            $ruleKey = self::ruleKey($alert['rule_id'], $alert['description']);
            $group = $groups[$key] ?? [
                'key'          => $key,
                'computers_id' => $alert['computers_id'],
                'agent'        => '',
                // Identifiant Wazuh affiché seulement s'il est simple (sinon empreinte interne).
                'rule_id'      => $ruleKey === trim($alert['rule_id']) ? $ruleKey : '',
                'description'  => '',
                'level'        => null,
                'count'        => 0,
                'repeated'     => false,
                'first'        => '',
                'last'         => '',
                'techniques'   => [],
                'alerts'       => [],
            ];
            $alertId = self::alertId($alertKey);
            if (isset($imported[$alertId])) {
                // Déjà dans un incident : le groupe est une répétition, sans nouvelle ligne.
                $group['repeated'] = true;
                $groups[$key] = $group;
                continue;
            }
            if (isset($seen[$alertId])) {
                continue;
            }
            $seen[$alertId] = true;
            $group['count']++;
            $group['agent'] = $group['agent'] !== '' ? $group['agent'] : trim($alert['agent']);
            $group['description'] = $group['description'] !== '' ? $group['description'] : $alert['description'];
            if ($alert['level'] !== null && ($group['level'] === null || $alert['level'] > $group['level'])) {
                $group['level'] = $alert['level'];
            }
            if ($alert['date'] !== '') {
                if ($group['first'] === '' || $alert['date'] < $group['first']) {
                    $group['first'] = $alert['date'];
                }
                if ($alert['date'] > $group['last']) {
                    $group['last'] = $alert['date'];
                }
            }
            foreach ($alert['techniques'] as $technique) {
                if (!in_array($technique, $group['techniques'], true)) {
                    $group['techniques'][] = $technique;
                }
            }
            $group['alerts'][] = [
                'id'    => $alert['id'],
                'key'   => $alertKey,
                'date'  => $alert['date'],
                'level' => $alert['level'],
            ];
            $groups[$key] = $group;
        }

        $result = [];
        foreach ($groups as $group) {
            if ($group['count'] === 0 || ($group['level'] ?? 0) < $minLevel) {
                continue;
            }
            usort(
                $group['alerts'],
                static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]
            );
            $result[] = $group + [
                'severity'          => WazuhEvidence::alertSeverity($group['level']),
                'incident_severity' => WazuhEvidence::incidentSeverity($group['level']),
            ];
        }
        usort(
            $result,
            static fn (array $a, array $b): int => [$b['level'] ?? -1, $b['last'], $a['key']]
                <=> [$a['level'] ?? -1, $a['last'], $b['key']]
        );

        return $result;
    }
}
