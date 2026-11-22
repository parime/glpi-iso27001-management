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

namespace GlpiPlugin\Grcmanager\Services\Compliance;

use GlpiPlugin\Grcmanager\Services\Control\AiActCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CatalogLanguage;
use GlpiPlugin\Grcmanager\Services\Control\CisControlsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\DoraCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Control\HdsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Etsi319401CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\MonServiceSecuriseCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ScfCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso22301CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso27701CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso42001CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\PciDssCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Soc2CatalogDefaults;

/**
 * Issue #789 : mesures propres de chaque référentiel intégré, lues dans son catalogue (exigences
 * NIS2, mesures d'hygiène ANSSI, sous-catégories du NIST CSF, contrôles CIS, articles de l'AI Act...),
 * avec les mesures de l'Annexe A que la correspondance du catalogue leur relie. Le plugin ne suit pas
 * d'état propre pour ces mesures : leur état se déduit des mesures de l'Annexe A reliées
 * (FrameworkAssessmentRules::fromMappedControls()), seulement quand ISO 27001 est activé.
 *
 * Logique pure, sans GLPI.
 */
final class FrameworkCatalogMeasures
{
    /**
     * Mesures du référentiel $key, dans l'ordre du catalogue ; liste vide pour ISO 27001 (ses mesures
     * sont celles de l'Annexe A) et pour une clé sans catalogue intégré.
     *
     * @return list<array{code: string, title: string, annex_a: list<string>}>
     */
    public static function measures(string $key, ?string $language = null): array
    {
        return match ($key) {
            FrameworkSelection::NIS2         => self::fromEntries(
                Nis2CatalogDefaults::REQUIREMENTS,
                static fn (string $code): string => Nis2CatalogDefaults::reference($code),
                $language
            ),
            FrameworkSelection::ANSSI        => self::fromEntries(
                AnssiHygieneCatalogDefaults::MEASURES,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::NIST_CSF     => self::nistCsf(),
            FrameworkSelection::CIS_CONTROLS => self::cisControls(),
            FrameworkSelection::DORA         => self::fromEntries(
                DoraCatalogDefaults::REQUIREMENTS,
                static fn (string $code): string => DoraCatalogDefaults::reference($code),
                $language
            ),
            FrameworkSelection::ISO27701     => self::fromEntries(
                Iso27701CatalogDefaults::OBJECTIVES,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::ISO22301     => self::fromEntries(
                Iso22301CatalogDefaults::REQUIREMENTS,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::ISO42001     => self::fromEntries(
                Iso42001CatalogDefaults::OBJECTIVES,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::PCI_DSS      => self::fromEntries(
                PciDssCatalogDefaults::REQUIREMENTS,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::SOC2         => self::fromEntries(
                Soc2CatalogDefaults::CRITERIA,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::AI_ACT       => self::fromEntries(
                AiActCatalogDefaults::ARTICLES,
                static fn (string $code): string => 'Art. ' . $code,
                $language,
                'title_'
            ),
            FrameworkSelection::HDS          => self::fromEntries(
                HdsCatalogDefaults::REQUIREMENTS,
                static fn (string $code): string => HdsCatalogDefaults::label($code),
                $language
            ),
            // Issue #878 : un thème MonServiceSécurisé, un paragraphe ETSI, un domaine SCF par ligne.
            FrameworkSelection::MSS          => self::fromEntries(
                MonServiceSecuriseCatalogDefaults::THEMES,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::ETSI_319401  => self::fromEntries(
                Etsi319401CatalogDefaults::REQUIREMENTS,
                static fn (string $code): string => $code,
                $language
            ),
            FrameworkSelection::SCF          => self::fromEntries(
                ScfCatalogDefaults::DOMAINS,
                static fn (string $code): string => $code,
                $language
            ),
            default                          => [],
        };
    }

    /**
     * @param array<int|string, array<string, mixed>> $entries
     * @param callable(string): string $reference
     * @return list<array{code: string, title: string, annex_a: list<string>}>
     */
    private static function fromEntries(
        array $entries,
        callable $reference,
        ?string $language,
        string $titlePrefix = ''
    ): array {
        $measures = [];
        foreach ($entries as $code => $entry) {
            $annexA = [];
            foreach ((array) ($entry['annex_a'] ?? []) as $annexACode) {
                $annexA[] = (string) $annexACode;
            }
            $measures[] = [
                'code'    => $reference((string) $code),
                'title'   => CatalogLanguage::pick($entry, $titlePrefix, $language),
                'annex_a' => $annexA,
            ];
        }

        return $measures;
    }

    /**
     * Sous-catégories du NIST CSF 2.0, reliées par la correspondance officielle du NIST (inversée).
     *
     * @return list<array{code: string, title: string, annex_a: list<string>}>
     */
    private static function nistCsf(): array
    {
        $annexA = [];
        foreach (ControlCrosswalkDefaults::CROSSWALK as $bareCode => $crosswalk) {
            foreach ($crosswalk['nist_csf'] as $subcategory) {
                $annexA[$subcategory][] = 'A.' . $bareCode;
            }
        }
        $measures = [];
        foreach (NistCsfCatalogDefaults::SUBCATEGORIES as $code => $entry) {
            $measures[] = ['code' => (string) $code, 'title' => $entry['text'], 'annex_a' => $annexA[$code] ?? []];
        }

        return $measures;
    }

    /**
     * Les 18 contrôles CIS v8, reliés par la correspondance indicative du plugin.
     *
     * @return list<array{code: string, title: string, annex_a: list<string>}>
     */
    private static function cisControls(): array
    {
        $measures = [];
        foreach (CisControlsCatalogDefaults::CONTROLS as $number => $name) {
            $measures[] = [
                'code'    => 'CIS ' . $number,
                'title'   => $name,
                'annex_a' => CisControlsCatalogDefaults::ANNEX_A[$number],
            ];
        }

        return $measures;
    }
}
