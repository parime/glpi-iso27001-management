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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\PhysicalSecurityTables;
use GlpiPlugin\Grcmanager\Services\Physical\PhysicalSecurityRules as Rules;

/**
 * Issue #778 : zones d'un site (publique, contrôlée, sensible, local technique) et leurs contrôles
 * d'accès, relevées à chaque évaluation de la sécurité physique (mesures A.7.1 à A.7.3 et A.7.6).
 *
 * Une zone appartient à une évaluation : la réévaluation suivante du site reprend les zones de la
 * précédente, qui reste telle qu'elle a été constatée. Droits de l'évaluation.
 */
class PhysicalSecurityZone
{
    public const TABLE = PhysicalSecurityTables::ZONES;

    /**
     * @return list<array<string, mixed>>
     */
    public static function getZones(int $assessmentId): array
    {
        global $DB;

        $zones = array_values(iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [PhysicalSecurityAssessment::FK => $assessmentId],
            'ORDER' => ['id'],
        ]), false));
        $order = array_flip(Rules::ZONE_TYPES);
        usort($zones, static fn (array $a, array $b): int => [
            $order[Rules::normalizeZoneType($a['zone_type'])], (int) $a['id'],
        ] <=> [$order[Rules::normalizeZoneType($b['zone_type'])], (int) $b['id']]);

        return $zones;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null champs à enregistrer, null sans nom
     */
    private static function fields(array $input): ?array
    {
        $name = Rules::cleanText($input['name'] ?? '', Rules::MAX_NAME_LENGTH);
        if ($name === '') {
            return null;
        }

        return [
            'name'                => $name,
            'zone_type'           => Rules::normalizeZoneType($input['zone_type'] ?? ''),
            'access_means'        => Rules::encodeAccessMeans(
                Rules::normalizeAccessMeans($input['access_means'] ?? [])
            ),
            'authorized_people'   => Rules::cleanText($input['authorized_people'] ?? ''),
            'instructions_posted' => empty($input['instructions_posted']) ? 0 : 1,
            'comment'             => Rules::cleanText($input['comment'] ?? ''),
            'date_mod'            => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function addZone(PhysicalSecurityAssessment $assessment, array $input): bool
    {
        global $DB;

        $fields = self::fields($input);
        if ($fields === null) {
            return false;
        }

        return (bool) $DB->insert(self::TABLE, $fields + [
            PhysicalSecurityAssessment::FK => $assessment->getID(),
            'date_creation'                => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function updateZone(PhysicalSecurityAssessment $assessment, int $zoneId, array $input): bool
    {
        global $DB;

        $fields = self::fields($input);
        if ($fields === null || !self::belongsTo($zoneId, $assessment)) {
            return false;
        }

        return (bool) $DB->update(self::TABLE, $fields, ['id' => $zoneId]);
    }

    public static function deleteZone(PhysicalSecurityAssessment $assessment, int $zoneId): bool
    {
        global $DB;

        if (!self::belongsTo($zoneId, $assessment)) {
            return false;
        }

        return (bool) $DB->delete(self::TABLE, ['id' => $zoneId]);
    }

    private static function belongsTo(int $zoneId, PhysicalSecurityAssessment $assessment): bool
    {
        return $zoneId > 0 && countElementsInTable(self::TABLE, [
            'id'                           => $zoneId,
            PhysicalSecurityAssessment::FK => $assessment->getID(),
        ]) > 0;
    }

    /** Réévaluation : zones de l'évaluation précédente du site, reprises telles quelles. */
    public static function copyZones(int $fromId, int $toId): void
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        foreach (self::getZones($fromId) as $zone) {
            unset($zone['id']);
            $zone[PhysicalSecurityAssessment::FK] = $toId;
            $zone['date_creation'] = $now;
            $zone['date_mod'] = $now;
            $DB->insert(self::TABLE, $zone);
        }
    }

    public static function purgeAssessment(int $assessmentId): void
    {
        global $DB;

        $DB->delete(self::TABLE, [PhysicalSecurityAssessment::FK => $assessmentId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function present(int $assessmentId): array
    {
        $types = PhysicalSecurity::zoneTypes();
        $means = PhysicalSecurity::accessMeans();
        $warnings = PhysicalSecurity::zoneWarningLabels();
        $rows = [];
        foreach (self::getZones($assessmentId) as $zone) {
            $type = Rules::normalizeZoneType($zone['zone_type']);
            $zoneMeans = Rules::decodeAccessMeans((string) $zone['access_means']);
            $posted = (int) $zone['instructions_posted'] === 1;
            $rows[] = [
                'id'                  => (int) $zone['id'],
                'name'                => (string) $zone['name'],
                'type'                => $type,
                'type_label'          => $types[$type][0],
                'type_class'          => $types[$type][1],
                'means'               => $zoneMeans,
                'means_labels'        => array_map(static fn (string $m): string => $means[$m], $zoneMeans),
                'authorized_people'   => (string) $zone['authorized_people'],
                'instructions_posted' => $posted,
                'comment'             => (string) $zone['comment'],
                'warnings'            => array_map(
                    static fn (string $w): string => $warnings[$w],
                    Rules::zoneWarnings($type, $zoneMeans, $posted)
                ),
            ];
        }

        return $rows;
    }

    public static function showForAssessment(PhysicalSecurityAssessment $assessment): void
    {
        $types = PhysicalSecurity::zoneTypes();
        TemplateRenderer::getInstance()->display('@grcmanager/physical_security_zones_tab.html.twig', [
            'assessment_id' => $assessment->getID(),
            'zones'         => self::present($assessment->getID()),
            'can_edit'      => $assessment->can($assessment->getID(), UPDATE),
            'action'        => PhysicalSecurityAssessment::getFormURL(),
            'zone_types'    => array_map(static fn (array $t): string => $t[0], $types),
            'zone_hints'    => array_map(static fn (array $t): string => $t[2], $types),
            'access_means'  => PhysicalSecurity::accessMeans(),
        ]);
    }
}
