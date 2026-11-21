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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Docker Inventory #10 : lecture seule des preuves du plugin Inventaire Docker par son API publique
 * (voir DockerInventoryEvidence). Toute absence (plugin inactif, API absente ou trop ancienne, droit
 * de lecture manquant, erreur de lecture) donne null : l'appelant n'affiche rien.
 *
 * La classe de l'API est appelée par son nom (chaîne) : GRC ne dépend pas du plugin pour se charger.
 */
final class DockerInventoryEvidenceProvider
{
    /** Méthode de l'API qui alimente chaque section, et le nombre de lignes demandées. */
    private const METHODS = [
        DockerInventoryEvidence::SECTION_INVENTORY       => 'inventory',
        DockerInventoryEvidence::SECTION_GAPS            => 'versionGaps',
        DockerInventoryEvidence::SECTION_VULNERABILITIES => 'vulnerabilities',
        DockerInventoryEvidence::SECTION_CONFIGURATION   => 'configuration',
        DockerInventoryEvidence::SECTION_CHANGES         => 'versionChanges',
    ];

    /** Lignes demandées à l'API : de la marge pour celles écartées par la revérification des entités. */
    private const FETCH_ROWS = 200;

    public static function isAvailable(): bool
    {
        $class = DockerInventoryEvidence::API_CLASS;
        if (!\Plugin::isPluginActive(DockerInventoryEvidence::PLUGIN_KEY) || !class_exists($class)) {
            return false;
        }
        $version = $class . '::VERSION';
        $canRead = [$class, 'canRead'];

        return defined($version)
            && DockerInventoryEvidence::isSupportedApiVersion(constant($version))
            && is_callable($canRead)
            && call_user_func($canRead) === true;
    }

    /**
     * Preuves Docker d'une mesure (voir DockerInventoryEvidence::CONTROLS), ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchForControl(string $code): ?array
    {
        if (!DockerInventoryEvidence::isRelevantControl($code) || !self::isAvailable()) {
            return null;
        }
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $raw = [];
        foreach (DockerInventoryEvidence::sectionsFor($code) as $section) {
            $raw[$section] = self::call($section, $now);
        }

        return DockerInventoryEvidence::build($code, $raw, self::allowedEntities(), $now);
    }

    /**
     * Services visibles dont l'image a des vulnérabilités critiques ou hautes (Harbor), pour la page
     * des vulnérabilités du parc. Null si le plugin ou ses analyses Harbor ne sont pas exploitables.
     *
     * @return array{rows: list<array<string, mixed>>, page_url: string}|null
     */
    public static function fetchVulnerableImages(): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        $data = self::call(DockerInventoryEvidence::SECTION_VULNERABILITIES, '');
        if ($data === null || !($data['has_harbor'] ?? false)) {
            return null;
        }
        $built = DockerInventoryEvidence::build('A.8.8', [
            DockerInventoryEvidence::SECTION_VULNERABILITIES => $data,
        ], self::allowedEntities(), '');

        return [
            'rows'     => DockerInventoryEvidence::vulnerableServices($data, self::allowedEntities()),
            'page_url' => (string) ($built[DockerInventoryEvidence::SECTION_VULNERABILITIES]['page_url'] ?? ''),
        ];
    }

    /**
     * Issue #916 : configuration à risque des services (A.8.9), par Evidence::hardening() du plugin
     * (0.12.0 et suivantes). Null sans le plugin, avec une version qui n'a pas cette méthode, sans
     * droit de lecture ou en cas d'erreur.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchHardening(): ?array
    {
        $callable = [DockerInventoryEvidence::API_CLASS, DockerHardeningEvidence::API_METHOD];
        if (!self::isAvailable() || !is_callable($callable)) {
            return null;
        }
        try {
            $result = call_user_func($callable, self::FETCH_ROWS);
        } catch (\Throwable $e) {
            return null;
        }

        return DockerHardeningEvidence::summary(
            $result,
            self::allowedEntities(),
            (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function call(string $section, string $now): ?array
    {
        $callable = [DockerInventoryEvidence::API_CLASS, self::METHODS[$section]];
        if (!is_callable($callable)) {
            return null;
        }
        $arguments = $section === DockerInventoryEvidence::SECTION_INVENTORY ? [] : [self::FETCH_ROWS];
        if ($section === DockerInventoryEvidence::SECTION_CHANGES) {
            $arguments = [DockerInventoryEvidence::changesSince($now), self::FETCH_ROWS];
        }
        try {
            $result = call_user_func_array($callable, $arguments);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($result) ? $result : null;
    }

    /**
     * Entités actives de la session et leurs parentes (objets récursifs visibles depuis l'entité).
     *
     * @return array<int, true>
     */
    private static function allowedEntities(): array
    {
        $allowed = [];
        foreach (
            array_merge(
                (array) ($_SESSION['glpiactiveentities'] ?? []),
                (array) ($_SESSION['glpiparententities'] ?? [])
            ) as $id
        ) {
            if (is_numeric($id)) {
                $allowed[(int) $id] = true;
            }
        }

        return $allowed;
    }
}
