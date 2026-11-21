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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\SbomTables;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use GlpiPlugin\Grcmanager\Services\Sbom\SbomParser;
use GlpiPlugin\Grcmanager\Services\Sbom\VexRules;
use Html;
use Session;

/**
 * Issue #869 : nomenclature logicielle (SBOM) et déclarations VEX des produits fabriqués ou
 * développés par l'organisation, en complément du développement sécurisé (#777) et des délais
 * de notification du CRA (#600). Un produit est une application du cœur (comme le registre du
 * développement sécurisé) ; une nomenclature est importée par version.
 *
 * - Onglet « SBOM et VEX » de l'application : import CycloneDX ou SPDX (JSON), composants,
 *   vulnérabilités rapprochées, déclarations VEX saisies ou importées (CycloneDX VEX, OpenVEX).
 * - Vulnérabilités rapprochées sans aucun appel extérieur : celles que cite le fichier lui-même
 *   (CycloneDX), et celles que la veille des vulnérabilités du parc (#160) connaît déjà pour le même
 *   produit et la même version (alias d'un produit surveillé ou CPE du composant).
 * - Preuves des mesures A.5.21, A.8.8 et A.8.28.
 *
 * Droits : lecture du plugin et application visible (droit natif et entité, can()) pour voir ;
 * mise à jour du plugin pour importer, déclarer et supprimer. Rien n'est modifié ailleurs.
 */
final class ProductSbom extends CommonGLPI
{
    /** Mesures qui reçoivent les preuves des nomenclatures. */
    public const CONTROL_CODES = ['A.5.21', 'A.8.8', 'A.8.28'];

    /** Nomenclatures détaillées dans l'onglet (les plus récentes) ; les autres sont listées. */
    public const DETAILED_SBOMS = 5;

    /** Composants affichés par nomenclature. */
    public const LISTED_COMPONENTS = 1000;

    public static function getTypeName($nb = 0)
    {
        return __('SBOM and VEX', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-packages';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/productsbom.form.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(Installer::RIGHT_NAME, READ) && Appliance::canView();
    }

    public static function canEdit(): bool
    {
        return self::canView() && (bool) Session::haveRight(Installer::RIGHT_NAME, UPDATE);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Appliance || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = countElementsInTable(SbomTables::SBOMS, ['appliances_id' => $item->getID()]);

        return [1 => self::createTabEntry(self::getTypeName(1), $count, null, self::getIcon())];
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
        PageHelp::show('productsbom');
        TemplateRenderer::getInstance()->display('@grcmanager/product_sbom_tab.html.twig', self::tabData($item));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tabData(Appliance $appliance): array
    {
        $appliancesId = (int) $appliance->getID();
        $statements = self::statements($appliancesId);
        $sboms = [];
        foreach (self::sboms($appliancesId) as $index => $sbom) {
            if ($index < self::DETAILED_SBOMS) {
                $components = self::components((int) $sbom['id']);
                $findings = self::findings($sbom, $components, $statements);
                $sbom['findings'] = $findings;
                $sbom['indicators'] = VexRules::indicators($findings);
                $sbom['listed_components'] = array_slice($components, 0, self::LISTED_COMPONENTS);
            }
            $sbom['date'] = Html::convDateTime((string) $sbom['date_creation']);
            $sbom['user_name'] = (int) $sbom['users_id'] > 0 ? getUserName((int) $sbom['users_id']) : '';
            $sboms[] = $sbom;
        }
        foreach ($statements as &$statement) {
            $statement['date'] = Html::convDateTime((string) $statement['date_mod']);
            $statement['user_name'] = (int) $statement['users_id'] > 0 ? getUserName((int) $statement['users_id']) : '';
        }
        unset($statement);

        return [
            'appliances_id'   => $appliancesId,
            'sboms'           => $sboms,
            'statements'      => array_reverse($statements),
            'can_edit'        => self::canEdit(),
            'action'          => self::getFormURL(),
            'statuses'        => self::statusLabels(),
            'status_colors'   => self::statusColors(),
            'justifications'  => self::justificationLabels(),
            'sources'         => self::sourceLabels(),
            'formats'         => self::formatLabels(),
            'max_mb'          => (int) (SbomParser::MAX_BYTES / 1024 / 1024),
            'max_components'  => SbomParser::MAX_COMPONENTS,
            'detailed'        => self::DETAILED_SBOMS,
            'listed'          => self::LISTED_COMPONENTS,
            'register_url'    => SecureDevelopment::getPageURL(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            VexRules::STATUS_AFFECTED            => __('Affected', 'grcmanager'),
            VexRules::STATUS_UNDER_INVESTIGATION => __('Under investigation', 'grcmanager'),
            VexRules::STATUS_FIXED               => __('Fixed', 'grcmanager'),
            VexRules::STATUS_NOT_AFFECTED        => __('Not affected', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function statusColors(): array
    {
        return [
            VexRules::STATUS_AFFECTED            => 'danger',
            VexRules::STATUS_UNDER_INVESTIGATION => 'warning',
            VexRules::STATUS_FIXED               => 'success',
            VexRules::STATUS_NOT_AFFECTED        => 'success',
        ];
    }

    /** @return array<string, string> */
    public static function justificationLabels(): array
    {
        return [
            'component_not_present'                             => __('Component not present', 'grcmanager'),
            'vulnerable_code_not_present'                       => __('Vulnerable code not present', 'grcmanager'),
            'vulnerable_code_not_in_execute_path'               => __('Vulnerable code never executed', 'grcmanager'),
            'vulnerable_code_cannot_be_controlled_by_adversary' => __(
                'Vulnerable code out of reach of an attacker',
                'grcmanager'
            ),
            'inline_mitigations_already_exist'                  => __('Protections already in place', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function sourceLabels(): array
    {
        return [
            VexRules::SOURCE_MANUAL      => __('Entered by hand', 'grcmanager'),
            SbomParser::FORMAT_CYCLONEDX => 'CycloneDX',
            SbomParser::FORMAT_OPENVEX   => 'OpenVEX',
            'sbom'                       => __('SBOM file', 'grcmanager'),
            'park'                       => __('Vulnerability watch', 'grcmanager'),
        ];
    }

    /** @return array<string, string> */
    public static function formatLabels(): array
    {
        return [SbomParser::FORMAT_CYCLONEDX => 'CycloneDX', SbomParser::FORMAT_SPDX => 'SPDX'];
    }

    public static function errorMessage(string $error): string
    {
        return match ($error) {
            SbomParser::ERROR_EMPTY     => __('The file is empty.', 'grcmanager'),
            SbomParser::ERROR_TOO_LARGE => sprintf(
                __('The file is larger than %d MB.', 'grcmanager'),
                (int) (SbomParser::MAX_BYTES / 1024 / 1024)
            ),
            SbomParser::ERROR_INVALID   => __('The file is not valid JSON, or it is nested too deeply.', 'grcmanager'),
            SbomParser::ERROR_NOT_SBOM  => __(
                'The file is neither a CycloneDX nor an SPDX 2 software bill of materials in JSON.',
                'grcmanager'
            ),
            SbomParser::ERROR_NOT_VEX   => __(
                'The file contains no VEX statement (CycloneDX with an analysis, or OpenVEX).',
                'grcmanager'
            ),
            SbomParser::ERROR_TOO_MANY  => sprintf(
                __('The file lists more than %d components or vulnerabilities: split it by product.', 'grcmanager'),
                SbomParser::MAX_COMPONENTS
            ),
            'vulnerability'               => __(
                'Enter a vulnerability identifier, such as CVE-2024-12345.',
                'grcmanager'
            ),
            'status'                      => __('Choose a status.', 'grcmanager'),
            'justification'               => __('Unknown justification.', 'grcmanager'),
            'not_affected_without_reason' => __(
                'A "not affected" statement needs a justification or an explanation.',
                'grcmanager'
            ),
            'version'                     => __('Enter the product version described by the file.', 'grcmanager'),
            default                       => __('The file could not be read.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Lecture.

    /**
     * Nomenclatures d'une application, les plus récentes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function sboms(int $appliancesId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => SbomTables::SBOMS,
                'WHERE' => ['appliances_id' => $appliancesId],
                'ORDER' => ['date_creation DESC', 'id DESC'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function components(int $sbomsId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => SbomTables::COMPONENTS,
                'WHERE' => ['plugin_grcmanager_sboms_id' => $sbomsId],
                'ORDER' => ['name', 'version'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Déclarations VEX d'une application, les plus anciennes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function statements(int $appliancesId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => SbomTables::VEX,
                'WHERE' => ['appliances_id' => $appliancesId],
                'ORDER' => ['date_mod', 'id'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Vulnérabilités des composants d'une nomenclature : celles du fichier, puis celles de la veille
     * du parc pour le même produit et la même version, chacune avec sa déclaration VEX.
     *
     * @param array<string, mixed> $sbom
     * @param list<array<string, mixed>> $components
     * @param list<array<string, mixed>> $statements
     * @return list<array<string, mixed>>
     */
    public static function findings(array $sbom, array $components, array $statements): array
    {
        global $DB;

        $byId = [];
        foreach ($components as $component) {
            $byId[(int) $component['id']] = $component;
        }
        $found = [];
        foreach (
            $DB->request([
                'FROM'  => SbomTables::VULNERABILITIES,
                'WHERE' => ['plugin_grcmanager_sboms_id' => (int) $sbom['id']],
            ]) as $row
        ) {
            $component = $byId[(int) $row['plugin_grcmanager_sbomcomponents_id']] ?? null;
            $key = $row['vulnerability_id'] . "\0" . (int) $row['plugin_grcmanager_sbomcomponents_id'];
            $found[$key] = [
                'vulnerability_id' => (string) $row['vulnerability_id'],
                'component'        => $component,
                'cvss'             => $row['cvss'] !== null ? (float) $row['cvss'] : null,
                'source'           => 'sbom',
            ];
        }
        foreach (self::parkMatches($components) as [$cveId, $componentId]) {
            $found[$cveId . "\0" . $componentId] ??= [
                'vulnerability_id' => $cveId,
                'component'        => $byId[$componentId],
                'cvss'             => null,
                'source'           => 'park',
            ];
        }
        if ($found === []) {
            return [];
        }

        $cves = array_values(array_unique(array_column($found, 'vulnerability_id')));
        $scores = [];
        $enrichments = ['FROM' => 'glpi_plugin_grcmanager_cveenrichments', 'WHERE' => ['cve_id' => $cves]];
        foreach ($DB->request($enrichments) as $row) {
            $scores[(string) $row['cve_id']] = $row['cvss_score'] !== null ? (float) $row['cvss_score'] : null;
        }
        $kev = [];
        $signals = ['FROM' => ParkVulnerabilitySync::SIGNALS_TABLE, 'WHERE' => ['cve_id' => $cves]];
        foreach ($DB->request($signals) as $row) {
            $kev[(string) $row['cve_id']] = (bool) $row['is_kev'];
        }

        $version = (string) $sbom['product_version'];
        $findings = [];
        foreach ($found as $finding) {
            $component = $finding['component'] ?? ['bom_ref' => '', 'purl' => '', 'name' => '', 'version' => ''];
            $id = $finding['vulnerability_id'];
            $statement = VexRules::statementFor($statements, $id, [
                'bom_ref' => (string) $component['bom_ref'],
                'purl'    => (string) $component['purl'],
                'name'    => (string) $component['name'],
            ], $version);
            $findings[] = [
                'vulnerability_id'  => $id,
                'is_cve'            => str_starts_with($id, 'CVE-'),
                'component_name'    => (string) $component['name'],
                'component_version' => (string) $component['version'],
                'component_key'     => (string) ($component['purl'] !== '' ? $component['purl'] : $component['name']),
                'cvss'              => $finding['cvss'] ?? ($scores[$id] ?? null),
                'is_kev'            => $kev[$id] ?? false,
                'source'            => $finding['source'],
                'status'            => $statement !== null ? (string) $statement['status'] : '',
                'justification'     => $statement !== null ? (string) $statement['justification'] : '',
                'detail'            => $statement !== null ? (string) $statement['detail'] : '',
            ];
        }
        // À traiter d'abord : affectées ou sans déclaration, puis par gravité.
        $rank = static fn (array $f): int => match ($f['status']) {
            VexRules::STATUS_AFFECTED => 0,
            '' => 1,
            VexRules::STATUS_UNDER_INVESTIGATION => 2,
            default => 3,
        };
        $order = static fn (array $f): array => [$rank($f), -($f['cvss'] ?? 0.0), $f['vulnerability_id']];
        usort($findings, static fn (array $a, array $b): int => $order($a) <=> $order($b));

        return $findings;
    }

    /**
     * Couples (CVE, composant) connus de la veille du parc : même produit surveillé (alias égal au
     * nom du composant, ou fabricant et produit de sa CPE) et même version. Aucun appel extérieur.
     *
     * @param list<array<string, mixed>> $components
     * @return list<array{0: string, 1: int}>
     */
    private static function parkMatches(array $components): array
    {
        global $DB;

        if ($components === []) {
            return [];
        }
        $aliases = [];
        foreach ($DB->request(['FROM' => ProductAlias::getTable()]) as $row) {
            $aliases[mb_strtolower((string) $row['alias'])] = (int) $row['plugin_grcmanager_canonicalproducts_id'];
        }
        $products = [];
        foreach ($DB->request(['FROM' => CanonicalProduct::getTable()]) as $row) {
            $products[strtolower($row['manufacturer'] . ':' . $row['product'])] = (int) $row['id'];
        }
        $pairs = [];
        foreach ($components as $component) {
            $version = (string) $component['version'];
            if ($version === '') {
                continue;
            }
            $name = mb_strtolower((string) $component['name']);
            $short = ($slash = strrpos($name, '/')) !== false ? substr($name, $slash + 1) : $name;
            $canonical = $aliases[$name] ?? ($aliases[$short] ?? 0);
            $cpe = VexRules::cpeVendorProduct((string) $component['cpe']);
            if ($canonical === 0 && $cpe !== null) {
                $canonical = $products[$cpe[0] . ':' . $cpe[1]] ?? 0;
            }
            if ($canonical > 0) {
                $pairs[$canonical . "\0" . $version][] = (int) $component['id'];
            }
        }
        if ($pairs === []) {
            return [];
        }
        $ids = [];
        $versions = [];
        foreach (array_keys($pairs) as $pair) {
            [$id, $version] = explode("\0", $pair, 2);
            $ids[] = (int) $id;
            $versions[] = $version;
        }
        $matches = [];
        foreach (
            $DB->request([
                'FROM'  => ParkVulnerabilitySync::PARK_CVES_TABLE,
                'WHERE' => [
                    'plugin_grcmanager_canonicalproducts_id' => array_values(array_unique($ids)),
                    'version'                                => array_values(array_unique($versions)),
                ],
            ]) as $row
        ) {
            $pair = $row['plugin_grcmanager_canonicalproducts_id'] . "\0" . $row['version'];
            foreach ($pairs[$pair] ?? [] as $componentId) {
                $matches[] = [(string) $row['cve_id'], $componentId];
            }
        }

        return $matches;
    }

    // ------------------------------------------------------------------ Écriture.

    /**
     * Importe une nomenclature pour une version de l'application ; les déclarations VEX qu'elle
     * contient (CycloneDX avec analyse) sont enregistrées aussi.
     *
     * @return array{error: string, id: int, components: int, vulnerabilities: int, statements: int}
     */
    public static function importSbom(int $appliancesId, string $version, string $content, string $fileName): array
    {
        global $DB;

        $result = ['error' => '', 'id' => 0, 'components' => 0, 'vulnerabilities' => 0, 'statements' => 0];
        $version = SbomParser::text($version, 100);
        if ($version === '') {
            return ['error' => 'version'] + $result;
        }
        $parsed = SbomParser::parseSbom($content);
        if ($parsed['error'] !== '') {
            return ['error' => $parsed['error']] + $result;
        }
        $DB->insert(SbomTables::SBOMS, [
            'appliances_id'   => $appliancesId,
            'product_version' => $version,
            'format'          => $parsed['format'],
            'spec_version'    => $parsed['spec_version'],
            'document_name'   => $parsed['name'],
            'file_name'       => SbomParser::text(basename($fileName), 255),
            'components'      => count($parsed['components']),
            'users_id'        => (int) Session::getLoginUserID(),
            'date_creation'   => self::now(),
        ]);
        $sbomsId = (int) $DB->insertId();
        $refs = [];
        foreach ($parsed['components'] as $component) {
            $DB->insert(SbomTables::COMPONENTS, ['plugin_grcmanager_sboms_id' => $sbomsId] + $component);
            $componentId = (int) $DB->insertId();
            foreach ([$component['bom_ref'], $component['purl']] as $ref) {
                if ($ref !== '') {
                    $refs[$ref] ??= $componentId;
                }
            }
        }
        $count = 0;
        foreach ($parsed['vulnerabilities'] as $vulnerability) {
            foreach ($vulnerability['components'] as $ref) {
                if (!isset($refs[$ref])) {
                    continue;
                }
                $DB->insert(SbomTables::VULNERABILITIES, [
                    'plugin_grcmanager_sboms_id'          => $sbomsId,
                    'plugin_grcmanager_sbomcomponents_id' => $refs[$ref],
                    'vulnerability_id'                    => $vulnerability['id'],
                    'cvss'                                => $vulnerability['cvss'],
                ]);
                $count++;
            }
        }
        $statements = self::saveStatements($appliancesId, $version, $parsed['vulnerabilities'], $parsed['format']);

        return [
            'error' => '', 'id' => $sbomsId, 'components' => count($parsed['components']),
            'vulnerabilities' => $count, 'statements' => $statements,
        ];
    }

    /**
     * Importe un document VEX (CycloneDX ou OpenVEX) pour une version de l'application (vide :
     * toutes les versions).
     *
     * @return array{error: string, statements: int}
     */
    public static function importVex(int $appliancesId, string $version, string $content): array
    {
        $parsed = SbomParser::parseVex($content);
        if ($parsed['error'] !== '') {
            return ['error' => $parsed['error'], 'statements' => 0];
        }

        return [
            'error'      => '',
            'statements' => self::saveStatements(
                $appliancesId,
                SbomParser::text($version, 100),
                $parsed['vulnerabilities'],
                $parsed['format']
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $vulnerabilities
     */
    private static function saveStatements(
        int $appliancesId,
        string $version,
        array $vulnerabilities,
        string $source
    ): int {
        $count = 0;
        foreach ($vulnerabilities as $vulnerability) {
            if ((string) $vulnerability['status'] === '') {
                continue;
            }
            $components = $vulnerability['components'] === [] ? [''] : $vulnerability['components'];
            foreach ($components as $component) {
                self::upsertStatement($appliancesId, [
                    'product_version'  => $version,
                    'vulnerability_id' => (string) $vulnerability['id'],
                    'component'        => (string) $component,
                    'status'           => (string) $vulnerability['status'],
                    'justification'    => (string) $vulnerability['justification'],
                    'detail'           => (string) $vulnerability['detail'],
                    'source'           => $source,
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Déclaration saisie dans l'onglet.
     *
     * @param array<string, mixed> $input
     * @return string code d'erreur, vide si enregistrée
     */
    public static function declare(int $appliancesId, array $input): string
    {
        $raw = $input['vulnerability_id'] ?? null;
        $id = SbomParser::vulnerabilityId(is_string($raw) ? trim($raw) : '');
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        $justification = is_string($input['justification'] ?? null) ? $input['justification'] : '';
        $detail = SbomParser::text($input['detail'] ?? '', 2000);
        $error = VexRules::validate($id, $status, $justification, $detail);
        if ($error !== '') {
            return $error;
        }
        self::upsertStatement($appliancesId, [
            'product_version'  => SbomParser::text($input['product_version'] ?? '', 100),
            'vulnerability_id' => $id,
            'component'        => SbomParser::text($input['component'] ?? '', 512),
            'status'           => $status,
            'justification'    => $justification,
            'detail'           => $detail,
            'source'           => VexRules::SOURCE_MANUAL,
        ]);

        return '';
    }

    /**
     * Une seule déclaration par application, version, vulnérabilité et composant : la nouvelle
     * remplace l'ancienne.
     *
     * @param array<string, string> $fields
     */
    private static function upsertStatement(int $appliancesId, array $fields): void
    {
        global $DB;

        $key = ['appliances_id' => $appliancesId] + array_intersect_key(
            $fields,
            array_flip(['product_version', 'vulnerability_id', 'component'])
        );
        $values = $fields + ['users_id' => (int) Session::getLoginUserID(), 'date_mod' => self::now()];
        $existing = $DB->request([
            'SELECT' => 'id', 'FROM' => SbomTables::VEX, 'WHERE' => $key, 'LIMIT' => 1,
        ])->current();
        if ($existing !== null) {
            $DB->update(SbomTables::VEX, $values, ['id' => (int) $existing['id']]);
        } else {
            $DB->insert(SbomTables::VEX, $key + $values);
        }
    }

    /** Application d'une nomenclature (0 si inconnue). */
    public static function applianceOfSbom(int $sbomsId): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => 'appliances_id', 'FROM' => SbomTables::SBOMS, 'WHERE' => ['id' => $sbomsId],
        ])->current();

        return $row !== null ? (int) $row['appliances_id'] : 0;
    }

    /** Application d'une déclaration (0 si inconnue). */
    public static function applianceOfStatement(int $statementsId): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => 'appliances_id', 'FROM' => SbomTables::VEX, 'WHERE' => ['id' => $statementsId],
        ])->current();

        return $row !== null ? (int) $row['appliances_id'] : 0;
    }

    public static function deleteSbom(int $sbomsId): void
    {
        global $DB;

        $DB->delete(SbomTables::VULNERABILITIES, ['plugin_grcmanager_sboms_id' => $sbomsId]);
        $DB->delete(SbomTables::COMPONENTS, ['plugin_grcmanager_sboms_id' => $sbomsId]);
        $DB->delete(SbomTables::SBOMS, ['id' => $sbomsId]);
    }

    public static function deleteStatement(int $statementsId): void
    {
        global $DB;

        $DB->delete(SbomTables::VEX, ['id' => $statementsId]);
    }

    private static function now(): string
    {
        return (string) (Session::getCurrentTime() ?? date('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------ Preuves.

    /**
     * Preuves sur la fiche des mesures A.5.21, A.8.8 et A.8.28 : applications du registre du
     * développement sécurisé avec une nomenclature, et vulnérabilités de la dernière nomenclature de
     * chaque application visible, par statut VEX (null pour une autre mesure ou sans le droit).
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        global $DB;

        if (!in_array($code, self::CONTROL_CODES, true) || !self::canView()) {
            return null;
        }
        $latest = [];
        foreach (
            $DB->request([
                'SELECT'     => [SbomTables::SBOMS . '.*'],
                'FROM'       => SbomTables::SBOMS,
                'INNER JOIN' => [
                    'glpi_appliances' => ['FKEY' => ['glpi_appliances' => 'id', SbomTables::SBOMS => 'appliances_id']],
                ],
                'WHERE'      => ['glpi_appliances.is_deleted' => 0]
                    + getEntitiesRestrictCriteria('glpi_appliances', '', '', true),
                'ORDER'      => [SbomTables::SBOMS . '.date_creation DESC', SbomTables::SBOMS . '.id DESC'],
            ]) as $row
        ) {
            $latest[(int) $row['appliances_id']] ??= $row;
        }
        $indicators = VexRules::indicators([]);
        foreach ($latest as $appliancesId => $sbom) {
            $findings = self::findings($sbom, self::components((int) $sbom['id']), self::statements($appliancesId));
            foreach (VexRules::indicators($findings) as $key => $value) {
                $indicators[$key] += $value;
            }
        }
        $register = array_column(SecureDevelopment::applications(), 'appliances_id');
        $withSbom = array_intersect(array_map('intval', $register), array_keys($latest));

        return [
            'code'        => $code,
            'products'    => count($latest),
            'register'    => count($register),
            'register_without' => count($register) - count($withSbom),
            'indicators'  => $indicators,
            'date'        => (string) Html::convDateTime(self::now()),
            'url'         => SecureDevelopment::getPageURL(),
        ];
    }
}
