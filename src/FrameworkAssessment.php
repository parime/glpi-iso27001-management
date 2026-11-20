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
use GlpiPlugin\Grcmanager\Services\Acn\AcnCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Acn\AcnRules;
use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkAssessmentRules;
use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkCatalogMeasures;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use Session;

/**
 * Issue #379 : évaluation de conformité par référentiel (écran front/frameworkassessment.php).
 * Pour chaque référentiel actif : mesures concernées, taux de mise en œuvre, taux de preuve et score
 * (mises en œuvre et prouvées), avec la liste des écarts. Lecture seule : rien n'est modifié, l'écran
 * renvoie vers chaque mesure.
 *
 * Issue #789 : chaque référentiel est évalué sur ses propres mesures.
 * - état suivi dans le plugin (BASIS_OWN) : ISO 27001 (Annexe A de la SoA), exigences d'un
 *   référentiel personnalisé, points du registre NIS2 Italia accessible ;
 * - catalogue intégré sans état propre (BASIS_MAPPED : exigences NIS2, mesures ANSSI, NIST CSF, CIS,
 *   AI Act...) : l'état de chaque mesure est déduit, à titre de correspondance, des mesures de
 *   l'Annexe A reliées, seulement si ISO 27001 est activé ;
 * - sinon (BASIS_NONE) : aucune évaluation, l'écran le dit.
 * Le référentiel détaillé est retenu pour la session de l'utilisateur.
 */
class FrameworkAssessment extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Mesures propres du référentiel, avec leur état suivi dans le plugin. */
    public const BASIS_OWN = 'own';

    /** Mesures propres du catalogue, état déduit de la correspondance avec l'Annexe A. */
    public const BASIS_MAPPED = 'mapped';

    /** Aucun état propre et ISO 27001 désactivé : pas d'évaluation. */
    public const BASIS_NONE = 'none';

    /** Issue #789 : référentiel détaillé, retenu pour la session de l'utilisateur. */
    public const SESSION_KEY = 'plugin_grcmanager_assessment_framework';

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
     * Dates de validité des documents liés à chaque fiche de $itemtype (null : preuve sans limite).
     *
     * @return array<int, list<string|null>> fiche => une entrée par document
     */
    private static function documentValidities(string $itemtype): array
    {
        global $DB;

        $validity = [];
        foreach (
            $DB->request([
                'SELECT' => ['items_id', 'documents_id', 'valid_until'],
                'FROM'   => Evidence::getTable(),
                'WHERE'  => ['itemtype' => $itemtype],
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
                'WHERE'  => ['itemtype' => $itemtype],
            ]) as $row
        ) {
            $itemId = (int) $row['items_id'];
            $documents[$itemId][] = $validity[$itemId][(int) $row['documents_id']] ?? null;
        }

        return $documents;
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

        $documents = self::documentValidities(Control::class);
        $controls = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code', 'applicability', 'implementation_status'],
                'FROM'   => Control::getTable(),
                // Issue #536 : mesures de la SoA suivie par l'entité active.
                'WHERE'  => EntityPerimeter::controlCriteria(),
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
     * Évaluation de chaque référentiel actif, dans l'ordre de la carte « conformité par référentiel »
     * (intégrés, registre NIS2 Italia, puis personnalisés).
     *
     * @param array<string, array{id: int, applicability: string, implementation_status: string,
     *     evidence: string}> $controls
     * @return array<string, array<string, mixed>> nom => key, basis, applicable, implementation_rate,
     *     evidence_rate, score, done, proven, compliant, gaps (voir FrameworkAssessmentRules)
     */
    public static function assessAll(array $controls, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $enabled = FrameworkConfig::enabled();
        $isoEnabled = in_array(FrameworkSelection::ISO27001, $enabled, true);
        $builtin = [];
        $custom = [];
        foreach (PilotageCards::frameworkFilters() as $label => [$key, $covers]) {
            if (!in_array($key, $enabled, true)) {
                continue;
            }
            $customId = CustomFrameworkCatalog::idFromKey($key);
            if ($customId !== null) {
                $custom[$label] = ['key' => $key, 'basis' => self::BASIS_OWN]
                    + FrameworkAssessmentRules::assessMeasures(self::customMeasures($customId));
                continue;
            }
            if ($key === FrameworkSelection::ISO27001) {
                // Les mesures de l'Annexe A sont celles d'ISO 27001.
                $builtin[$label] = ['key' => $key, 'basis' => self::BASIS_OWN]
                    + FrameworkAssessmentRules::assess($controls, $covers);
                continue;
            }
            $catalog = FrameworkCatalogMeasures::measures($key);
            if (!$isoEnabled) {
                // ISO 27001 désactivé : l'Annexe A ne sert pas de base, le référentiel n'a pas d'état.
                $builtin[$label] = ['key' => $key, 'basis' => self::BASIS_NONE, 'measures' => count($catalog)]
                    + FrameworkAssessmentRules::assessMeasures([]);
                continue;
            }
            $builtin[$label] = ['key' => $key, 'basis' => self::BASIS_MAPPED, 'measures' => count($catalog)]
                + FrameworkAssessmentRules::assessMeasures(self::mappedMeasures($catalog, $controls));
        }
        $acn = self::acnMeasures($today);
        if ($acn !== null) {
            $builtin[FrameworkSelection::FRAMEWORKS[FrameworkSelection::ACN_IT]] = [
                'key'   => FrameworkSelection::ACN_IT,
                'basis' => self::BASIS_OWN,
            ] + FrameworkAssessmentRules::assessMeasures($acn);
        }

        return $builtin + $custom;
    }

    /**
     * Mesures propres d'un référentiel intégré, leur état déduit des mesures de l'Annexe A reliées
     * (celles de la SoA de l'entité active).
     *
     * @param list<array{code: string, title: string, annex_a: list<string>}> $catalog
     * @param array<string, array{id: int, applicability: string, implementation_status: string,
     *     evidence: string}> $controls
     * @return list<array<string, mixed>>
     */
    private static function mappedMeasures(array $catalog, array $controls): array
    {
        $statuses = Control::getImplementationStatuses();
        $measures = [];
        foreach ($catalog as $measure) {
            $rows = [];
            $links = [];
            foreach ($measure['annex_a'] as $code) {
                if (!isset($controls[$code])) {
                    continue;
                }
                $rows[] = $controls[$code];
                $links[] = ['code' => $code, 'url' => Control::getFormURLWithID($controls[$code]['id'])];
            }
            $state = FrameworkAssessmentRules::fromMappedControls($rows);
            $measures[] = [
                'code'                  => $measure['code'],
                'id'                    => 0,
                'title'                 => $measure['title'],
                'url'                   => '',
                'controls'              => $links,
                'status_label'          => $state['mapped']
                    ? ($statuses[$state['implementation_status']] ?? $state['implementation_status'])
                    : __('No mapped control', 'grcmanager'),
                'applicable'            => $state['applicable'],
                'implementation_status' => $state['implementation_status'],
                'evidence'              => $state['evidence'],
            ];
        }

        return $measures;
    }

    /**
     * Exigences actives d'un référentiel personnalisé, évaluées sur leur statut d'auto-évaluation
     * (aucune preuve n'y est rattachée : evidence = null).
     *
     * @return list<array<string, mixed>>
     */
    private static function customMeasures(int $frameworkId): array
    {
        $statuses = CustomRequirement::getStatuses();
        $url = CustomFramework::getFormURLWithID($frameworkId) . '#grcmanager-cf-requirements';
        $measures = [];
        foreach (CustomFramework::getRequirements($frameworkId) as $row) {
            if ((int) $row['is_removed'] === 1) {
                continue;
            }
            $status = array_key_exists((string) $row['status'], $statuses)
                ? (string) $row['status'] : CustomRequirement::STATUS_NONE;
            $measures[] = [
                'code'         => (string) $row['code'],
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'url'          => $url,
                'status_label' => $statuses[$status]['label'],
                'evidence'     => null,
            ] + FrameworkAssessmentRules::fromCustomStatus($status);
        }

        return $measures;
    }

    /**
     * Points du registre NIS2 Italia que la personne peut ouvrir (celui de l'entité active d'abord),
     * avec leur état de preuve (documents liés au point). Null quand le référentiel est désactivé,
     * que le droit manque ou qu'aucun registre n'est accessible.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function acnMeasures(string $today): ?array
    {
        if (!AcnRequirement::canView()) {
            return null;
        }
        $entityId = AcnRequirement::selectedRegister(AcnRequirement::availableRegisters(), null);
        if ($entityId === null) {
            return null;
        }
        $documents = self::documentValidities(AcnRequirement::class);
        $statuses = Control::getImplementationStatuses();
        $measures = [];
        foreach (AcnRequirement::registerRows($entityId) as $row) {
            $id = (int) $row['id'];
            $code = (string) $row['code'];
            $status = AcnRules::normalizeStatus($row['implementation_status'] ?? '');
            $summary = AcnCatalogDefaults::pointSummary($code, (int) $row['point']);
            $measures[] = [
                'code'                  => AcnCatalogDefaults::reference($code, (int) $row['point']),
                'id'                    => $id,
                'title'                 => $summary !== '' ? $summary : AcnCatalogDefaults::measureTitle($code),
                'url'                   => AcnRequirement::getFormURLWithID($id),
                'status_label'          => $statuses[$status] ?? $status,
                'applicable'            => AcnRules::isApplicable($row),
                'implementation_status' => $status,
                'evidence'              => FrameworkAssessmentRules::evidenceStatus($documents[$id] ?? [], $today),
            ];
        }

        return $measures;
    }

    /**
     * Issue #789 : référentiel à détailler. Celui demandé s'il est évalué (il est alors retenu pour
     * la session) ; une demande vide oublie le choix ; sans demande, le choix retenu s'il est
     * toujours évalué. Une clé inconnue n'est jamais retenue.
     *
     * @param array<string, array<string, mixed>> $assessments voir assessAll()
     */
    public static function selectedFramework(array $assessments, mixed $requested): ?string
    {
        $keys = array_map(static fn (array $a): string => (string) $a['key'], array_values($assessments));
        if ($requested === '') {
            unset($_SESSION[self::SESSION_KEY]);

            return null;
        }
        if (is_string($requested) && in_array($requested, $keys, true)) {
            $_SESSION[self::SESSION_KEY] = $requested;

            return $requested;
        }
        $remembered = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($remembered) && in_array($remembered, $keys, true) ? $remembered : null;
    }

    /**
     * Détail du référentiel $key : ses écarts prêts à afficher (code, intitulé, lien, statut, preuve
     * et, pour un référentiel mesuré par l'Annexe A, ses propres références de chaque mesure).
     *
     * @param array<string, array<string, mixed>> $assessments voir assessAll()
     * @return array<string, mixed>|null
     */
    public static function detail(array $assessments, ?string $key): ?array
    {
        if ($key === null) {
            return null;
        }
        foreach ($assessments as $label => $assessment) {
            if ($assessment['key'] !== $key) {
                continue;
            }
            $gaps = [];
            $statuses = Control::getImplementationStatuses();
            /** @var list<array<string, mixed>> $rows */
            $rows = $assessment['gaps'];
            foreach ($rows as $gap) {
                if ($key === FrameworkSelection::ISO27001) {
                    // Mesures de l'Annexe A : intitulé et lien vers la fiche.
                    $code = (string) $gap['code'];
                    $status = (string) $gap['implementation_status'];
                    $gap += [
                        'title'        => Control::getControlTitle($code),
                        'url'          => Control::getFormURLWithID((int) $gap['id']),
                        'status_label' => $statuses[$status] ?? $status,
                    ];
                }
                $gaps[] = $gap + ['controls' => []];
            }

            return [
                'label'       => (string) $label,
                'gaps'        => $gaps,
                'manage_url'  => self::manageUrl($key),
            ] + $assessment;
        }

        return null;
    }

    /** Écran des mesures propres du référentiel (vide pour ISO 27001 : les fiches sont liées). */
    private static function manageUrl(string $key): string
    {
        $customId = CustomFrameworkCatalog::idFromKey($key);
        if ($customId !== null) {
            return CustomFramework::getFormURLWithID($customId);
        }

        if ($key === FrameworkSelection::ACN_IT) {
            return AcnRequirement::getSearchURL();
        }
        // Référentiel intégré : sa carte sur la page Référentiels (catalogue et correspondance).
        $cards = [
            FrameworkSelection::NIS2 => 'nis2', FrameworkSelection::ANSSI => 'anssi',
            FrameworkSelection::NIST_CSF => 'nist', FrameworkSelection::CIS_CONTROLS => 'cis',
            FrameworkSelection::DORA => 'dora', FrameworkSelection::ISO27701 => 'iso27701',
            FrameworkSelection::ISO22301 => 'iso22301', FrameworkSelection::ISO42001 => 'iso42001',
            FrameworkSelection::PCI_DSS => 'pci-dss', FrameworkSelection::SOC2 => 'soc2',
            FrameworkSelection::AI_ACT => 'ai-act', FrameworkSelection::HDS => 'hds',
        ];
        if (!isset($cards[$key])) {
            return '';
        }
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/referentiels.php#grcmanager-'
            . $cards[$key] . '-body';
    }
}
