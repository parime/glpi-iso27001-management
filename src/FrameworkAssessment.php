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
use Document_Item;
use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkAssessmentRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use Session;

/**
 * Issue #379 : évaluation de conformité par référentiel (écran front/frameworkassessment.php).
 * Pour chaque référentiel actif : mesures de l'Annexe A concernées, taux de mise en œuvre, taux de
 * preuve et score (mises en œuvre et prouvées), avec la liste des écarts. Lecture seule : rien n'est
 * modifié, l'écran renvoie vers chaque mesure.
 */
class FrameworkAssessment extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Assessment by framework', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/frameworkassessment.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
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

    /**
     * Mesures de l'Annexe A avec leur état de preuve, par code.
     *
     * @return array<string, array{id: int, applicability: string, implementation_status: string,
     *     evidence: string}>
     */
    public static function controls(string $today): array
    {
        global $DB;

        // Date de validité de chaque document lié à une mesure (absente : preuve sans limite).
        $validity = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'documents_id', 'valid_until'],
                'FROM'   => Evidence::getTable(),
                'WHERE'  => ['itemtype' => Control::class],
            ]) as $row
        ) {
            $validity[(int) $row['items_id']][(int) $row['documents_id']] = $row['valid_until'] !== null
                ? (string) $row['valid_until'] : null;
        }
        $documents = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'documents_id'],
                'FROM'   => Document_Item::getTable(),
                'WHERE'  => ['itemtype' => Control::class],
            ]) as $row
        ) {
            $controlId = (int) $row['items_id'];
            $documents[$controlId][] = $validity[$controlId][(int) $row['documents_id']] ?? null;
        }

        $controls = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code', 'applicability', 'implementation_status'],
                'FROM'   => Control::getTable(),
            ]) as $row
        ) {
            $id = (int) $row['id'];
            $controls[(string) $row['code']] = [
                'id'                    => $id,
                'applicability'         => (string) $row['applicability'],
                'implementation_status' => (string) $row['implementation_status'],
                'evidence'              => FrameworkAssessmentRules::evidenceStatus($documents[$id] ?? [], $today),
            ];
        }

        return $controls;
    }

    /**
     * Évaluation de chaque référentiel actif, dans l'ordre de la carte « conformité par référentiel ».
     *
     * @param array<string, array{id: int, applicability: string, implementation_status: string,
     *     evidence: string}> $controls
     * @return array<string, array{key: string, applicable: int, implementation_rate: float|null,
     *     evidence_rate: float|null, score: float|null, done: int, proven: int, compliant: int,
     *     gaps: list<array{code: string, id: int, implementation_status: string, evidence: string}>}>
     */
    public static function assessAll(array $controls): array
    {
        $enabled = FrameworkConfig::enabled();
        $result = [];
        foreach (PilotageCards::frameworkFilters() as $label => [$key, $covers]) {
            if (!in_array($key, $enabled, true)) {
                continue;
            }
            $result[$label] = ['key' => $key] + FrameworkAssessmentRules::assess($controls, $covers);
        }

        return $result;
    }
}
