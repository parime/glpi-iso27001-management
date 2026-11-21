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

namespace GlpiPlugin\Grcmanager\Services\Sbom;

use GlpiPlugin\Grcmanager\Services\Access\AdAuditReports;

/**
 * Issue #869 : lecture sûre d'une nomenclature logicielle (SBOM) en JSON, CycloneDX ou SPDX 2.x,
 * et d'un document VEX (CycloneDX VEX ou OpenVEX).
 *
 * Lecture sûre :
 * - taille limitée (MAX_BYTES), refusée avant tout décodage ;
 * - profondeur du JSON bornée (MAX_DEPTH, refus de json_decode au-delà) ;
 * - nombre de composants et de vulnérabilités borné (MAX_COMPONENTS, MAX_VULNERABILITIES) : au-delà,
 *   le fichier est refusé plutôt que lu en partie (une nomenclature incomplète ne prouve rien) ;
 * - seuls quelques champs texte sont gardés, coupés à la longueur de leur colonne et nettoyés des
 *   balises et des caractères de contrôle ; aucune adresse du fichier n'est suivie.
 */
final class SbomParser
{
    public const FORMAT_CYCLONEDX = 'cyclonedx';

    public const FORMAT_SPDX = 'spdx';

    public const FORMAT_OPENVEX = 'openvex';

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_DEPTH = 64;

    public const MAX_COMPONENTS = 10000;

    public const MAX_VULNERABILITIES = 5000;

    public const ERROR_EMPTY = 'empty';

    public const ERROR_TOO_LARGE = 'too_large';

    public const ERROR_INVALID = 'invalid';

    public const ERROR_NOT_SBOM = 'not_sbom';

    public const ERROR_NOT_VEX = 'not_vex';

    public const ERROR_TOO_MANY = 'too_many';

    /** Identifiant de vulnérabilité gardé (CVE, GHSA, OSV...). */
    private const VULNERABILITY_ID = '/^[A-Za-z][A-Za-z0-9]{1,15}-[A-Za-z0-9][A-Za-z0-9._:-]{2,46}$/';

    /**
     * @return array{error: string, format: string, spec_version: string, name: string,
     *     components: list<array<string, string>>, vulnerabilities: list<array<string, mixed>>}
     */
    public static function emptyResult(): array
    {
        return [
            'error' => '', 'format' => '', 'spec_version' => '', 'name' => '',
            'components' => [], 'vulnerabilities' => [],
        ];
    }

    /**
     * Nomenclature CycloneDX ou SPDX. Les vulnérabilités d'un fichier CycloneDX (souvent ajoutées
     * par l'outil d'analyse) et leur analyse VEX éventuelle sont lues aussi.
     *
     * @return array{error: string, format: string, spec_version: string, name: string,
     *     components: list<array<string, string>>, vulnerabilities: list<array<string, mixed>>}
     */
    public static function parseSbom(string $content): array
    {
        $data = self::decode($content);
        if (is_string($data)) {
            return ['error' => $data] + self::emptyResult();
        }
        if (self::isCycloneDx($data)) {
            return self::cycloneDx($data);
        }
        $spdx = $data['spdxVersion'] ?? null;
        if (is_string($spdx) && str_starts_with($spdx, 'SPDX-2.')) {
            return self::spdx($data);
        }

        return ['error' => self::ERROR_NOT_SBOM] + self::emptyResult();
    }

    /**
     * Document VEX : CycloneDX (vulnérabilités avec leur analyse) ou OpenVEX (déclarations).
     * Seules les vulnérabilités qui portent un statut sont gardées.
     *
     * @return array{error: string, format: string, spec_version: string, name: string,
     *     components: list<array<string, string>>, vulnerabilities: list<array<string, mixed>>}
     */
    public static function parseVex(string $content): array
    {
        $data = self::decode($content);
        if (is_string($data)) {
            return ['error' => $data === self::ERROR_NOT_SBOM ? self::ERROR_NOT_VEX : $data] + self::emptyResult();
        }
        if (self::isCycloneDx($data)) {
            $result = self::cycloneDx($data);
            if ($result['error'] !== '') {
                return $result;
            }
            $result['vulnerabilities'] = array_values(array_filter(
                $result['vulnerabilities'],
                static fn (array $v): bool => $v['status'] !== ''
            ));

            return $result['vulnerabilities'] === []
                ? ['error' => self::ERROR_NOT_VEX] + self::emptyResult()
                : $result;
        }
        $context = $data['@context'] ?? '';
        if (is_string($context) && str_starts_with($context, 'https://openvex.dev/ns')) {
            return self::openVex($data);
        }

        return ['error' => self::ERROR_NOT_VEX] + self::emptyResult();
    }

    /**
     * @return array<mixed>|string tableau décodé, ou code d'erreur
     */
    private static function decode(string $content): array|string
    {
        if (strlen($content) > self::MAX_BYTES) {
            return self::ERROR_TOO_LARGE;
        }
        $text = AdAuditReports::toUtf8($content);
        if ($text === null) {
            return self::ERROR_INVALID;
        }
        if (trim($text) === '') {
            return self::ERROR_EMPTY;
        }
        try {
            $data = json_decode($text, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return self::ERROR_INVALID;
        }

        return is_array($data) && !array_is_list($data) ? $data : self::ERROR_NOT_SBOM;
    }

    /**
     * @param array<mixed> $data
     */
    private static function isCycloneDx(array $data): bool
    {
        return ($data['bomFormat'] ?? null) === 'CycloneDX';
    }

    /**
     * @param array<mixed> $data
     * @return array{error: string, format: string, spec_version: string, name: string,
     *     components: list<array<string, string>>, vulnerabilities: list<array<string, mixed>>}
     */
    private static function cycloneDx(array $data): array
    {
        $result = self::emptyResult();
        $result['format'] = self::FORMAT_CYCLONEDX;
        $result['spec_version'] = self::text($data['specVersion'] ?? '', 32);
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $root = is_array($metadata['component'] ?? null) ? $metadata['component'] : [];
        $result['name'] = self::text($root['name'] ?? '', 255);

        // Composants imbriqués (components dans components) : parcours en largeur, borné.
        $queue = is_array($data['components'] ?? null) ? array_values($data['components']) : [];
        $components = [];
        $seen = 0;
        while ($queue !== []) {
            $item = array_shift($queue);
            if (++$seen > self::MAX_COMPONENTS) {
                return ['error' => self::ERROR_TOO_MANY] + self::emptyResult();
            }
            if (!is_array($item)) {
                continue;
            }
            $component = self::cycloneDxComponent($item);
            if ($component !== null) {
                $components[] = $component;
            }
            if (is_array($item['components'] ?? null)) {
                array_push($queue, ...array_values($item['components']));
            }
        }
        $result['components'] = $components;

        $vulnerabilities = is_array($data['vulnerabilities'] ?? null) ? $data['vulnerabilities'] : [];
        if (count($vulnerabilities) > self::MAX_VULNERABILITIES) {
            return ['error' => self::ERROR_TOO_MANY] + self::emptyResult();
        }
        foreach ($vulnerabilities as $vulnerability) {
            $parsed = is_array($vulnerability) ? self::cycloneDxVulnerability($vulnerability) : null;
            if ($parsed !== null) {
                $result['vulnerabilities'][] = $parsed;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $item
     * @return array<string, string>|null
     */
    private static function cycloneDxComponent(array $item): ?array
    {
        $name = self::text($item['name'] ?? '', 255);
        if ($name === '') {
            return null;
        }
        $group = self::text($item['group'] ?? '', 255);
        $supplier = is_array($item['supplier'] ?? null) ? ($item['supplier']['name'] ?? '') : '';

        return [
            'bom_ref'  => self::text($item['bom-ref'] ?? '', 255),
            'name'     => $group !== '' ? self::text($group . '/' . $name, 255) : $name,
            'version'  => self::text($item['version'] ?? '', 100),
            'type'     => self::text($item['type'] ?? '', 32),
            'purl'     => self::text($item['purl'] ?? '', 512),
            'cpe'      => self::text($item['cpe'] ?? '', 512),
            'supplier' => self::text($supplier !== '' ? $supplier : ($item['publisher'] ?? ''), 255),
            'licenses' => self::cycloneDxLicenses($item['licenses'] ?? null),
        ];
    }

    /**
     * @param array<mixed> $vulnerability
     * @return array<string, mixed>|null
     */
    private static function cycloneDxVulnerability(array $vulnerability): ?array
    {
        $id = self::vulnerabilityId($vulnerability['id'] ?? '');
        if ($id === '') {
            return null;
        }
        $refs = [];
        foreach (is_array($vulnerability['affects'] ?? null) ? $vulnerability['affects'] : [] as $affect) {
            $ref = is_array($affect) ? self::text($affect['ref'] ?? '', 512) : '';
            // Référence vers une autre nomenclature : urn:cdx:<série>/<version>#<bom-ref>.
            $hash = strrpos($ref, '#');
            if ($hash !== false && str_starts_with($ref, 'urn:cdx:')) {
                $ref = substr($ref, $hash + 1);
            }
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }
        $score = null;
        foreach (is_array($vulnerability['ratings'] ?? null) ? $vulnerability['ratings'] : [] as $rating) {
            $value = is_array($rating) ? ($rating['score'] ?? null) : null;
            if (is_int($value) || is_float($value)) {
                $score = max($score ?? 0.0, min(10.0, max(0.0, (float) $value)));
            }
        }
        $analysis = is_array($vulnerability['analysis'] ?? null) ? $vulnerability['analysis'] : [];

        return [
            'id'            => $id,
            'components'    => array_values(array_unique($refs)),
            'cvss'          => $score,
            'status'        => VexRules::fromCycloneDxState(self::text($analysis['state'] ?? '', 32)),
            'justification' => VexRules::fromCycloneDxJustification(self::text($analysis['justification'] ?? '', 64)),
            'detail'        => self::text($analysis['detail'] ?? '', 2000),
        ];
    }

    private static function cycloneDxLicenses(mixed $licenses): string
    {
        $names = [];
        foreach (is_array($licenses) ? $licenses : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $license = is_array($entry['license'] ?? null) ? $entry['license'] : [];
            $name = self::text($entry['expression'] ?? ($license['id'] ?? ($license['name'] ?? '')), 255);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return self::text(implode(', ', array_unique($names)), 255);
    }

    /**
     * @param array<mixed> $data
     * @return array{error: string, format: string, spec_version: string, name: string,
     *     components: list<array<string, string>>, vulnerabilities: list<array<string, mixed>>}
     */
    private static function spdx(array $data): array
    {
        $result = self::emptyResult();
        $result['format'] = self::FORMAT_SPDX;
        $result['spec_version'] = self::text(substr((string) $data['spdxVersion'], 5), 32);
        $result['name'] = self::text($data['name'] ?? '', 255);

        // Le paquet décrit par le document est le produit lui-même, pas l'un de ses composants.
        $described = is_array($data['documentDescribes'] ?? null) ? $data['documentDescribes'] : [];
        foreach (is_array($data['relationships'] ?? null) ? $data['relationships'] : [] as $rel) {
            if (
                is_array($rel) && ($rel['relationshipType'] ?? '') === 'DESCRIBES'
                && ($rel['spdxElementId'] ?? '') === 'SPDXRef-DOCUMENT'
            ) {
                $described[] = $rel['relatedSpdxElement'] ?? '';
            }
        }
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : [];
        if (count($packages) > self::MAX_COMPONENTS + count($described)) {
            return ['error' => self::ERROR_TOO_MANY] + self::emptyResult();
        }
        foreach ($packages as $package) {
            if (!is_array($package) || in_array($package['SPDXID'] ?? '', $described, true)) {
                continue;
            }
            $component = self::spdxComponent($package);
            if ($component !== null) {
                $result['components'][] = $component;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $package
     * @return array<string, string>|null
     */
    private static function spdxComponent(array $package): ?array
    {
        $name = self::text($package['name'] ?? '', 255);
        if ($name === '') {
            return null;
        }
        $purl = '';
        $cpe = '';
        foreach (is_array($package['externalRefs'] ?? null) ? $package['externalRefs'] : [] as $ref) {
            $type = is_array($ref) ? self::text($ref['referenceType'] ?? '', 32) : '';
            $locator = is_array($ref) ? self::text($ref['referenceLocator'] ?? '', 512) : '';
            if ($type === 'purl' && $purl === '') {
                $purl = $locator;
            } elseif (str_starts_with($type, 'cpe2') && $cpe === '') {
                $cpe = $locator;
            }
        }
        $license = self::spdxValue($package['licenseConcluded'] ?? '');
        $supplier = preg_replace('/^(Organization|Person|Tool):\s*/', '', self::spdxValue($package['supplier'] ?? ''));

        return [
            'bom_ref'  => self::text($package['SPDXID'] ?? '', 255),
            'name'     => $name,
            'version'  => self::text(self::spdxValue($package['versionInfo'] ?? ''), 100),
            'type'     => self::text(strtolower(self::spdxValue($package['primaryPackagePurpose'] ?? '')), 32),
            'purl'     => $purl,
            'cpe'      => $cpe,
            'supplier' => self::text($supplier ?? '', 255),
            'licenses' => self::text(
                $license !== '' ? $license : self::spdxValue($package['licenseDeclared'] ?? ''),
                255
            ),
        ];
    }

    /** Valeur SPDX, vide pour NOASSERTION et NONE. */
    private static function spdxValue(mixed $value): string
    {
        $text = self::text($value, 512);

        return in_array($text, ['NOASSERTION', 'NONE'], true) ? '' : $text;
    }

    /**
     * @param array<mixed> $data
     * @return array{error: string, format: string, spec_version: string, name: string,
     *     components: list<array<string, string>>, vulnerabilities: list<array<string, mixed>>}
     */
    private static function openVex(array $data): array
    {
        $result = self::emptyResult();
        $result['format'] = self::FORMAT_OPENVEX;
        $result['spec_version'] = self::text($data['version'] ?? '', 32);
        $result['name'] = self::text($data['@id'] ?? '', 255);
        $statements = is_array($data['statements'] ?? null) ? $data['statements'] : [];
        if (count($statements) > self::MAX_VULNERABILITIES) {
            return ['error' => self::ERROR_TOO_MANY] + self::emptyResult();
        }
        foreach ($statements as $statement) {
            $parsed = is_array($statement) ? self::openVexStatement($statement) : null;
            if ($parsed !== null) {
                $result['vulnerabilities'][] = $parsed;
            }
        }

        return $result['vulnerabilities'] === [] ? ['error' => self::ERROR_NOT_VEX] + self::emptyResult() : $result;
    }

    /**
     * @param array<mixed> $statement
     * @return array<string, mixed>|null
     */
    private static function openVexStatement(array $statement): ?array
    {
        $vulnerability = $statement['vulnerability'] ?? '';
        $id = self::vulnerabilityId(
            is_array($vulnerability) ? ($vulnerability['name'] ?? ($vulnerability['@id'] ?? '')) : $vulnerability
        );
        $status = VexRules::fromOpenVexStatus(self::text($statement['status'] ?? '', 32));
        if ($id === '' || $status === '') {
            return null;
        }
        $refs = [];
        foreach (is_array($statement['products'] ?? null) ? $statement['products'] : [] as $product) {
            $subs = is_array($product) && is_array($product['subcomponents'] ?? null) ? $product['subcomponents'] : [];
            foreach ($subs as $sub) {
                $ref = self::text(is_array($sub) ? ($sub['@id'] ?? '') : $sub, 512);
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
        }
        $detail = '';
        foreach (['impact_statement', 'action_statement', 'status_notes'] as $key) {
            $detail = $detail !== '' ? $detail : self::text($statement[$key] ?? '', 2000);
        }

        return [
            'id'            => $id,
            'components'    => array_values(array_unique($refs)),
            'cvss'          => null,
            'status'        => $status,
            'justification' => VexRules::justification(self::text($statement['justification'] ?? '', 64)),
            'detail'        => $detail,
        ];
    }

    /** Identifiant de vulnérabilité valide (CVE en majuscules), vide sinon. */
    public static function vulnerabilityId(mixed $value): string
    {
        $id = self::text($value, 64);
        if (preg_match(self::VULNERABILITY_ID, $id) !== 1) {
            return '';
        }

        return str_starts_with(strtoupper($id), 'CVE-') ? strtoupper($id) : $id;
    }

    /** Texte sans balises ni caractères de contrôle, coupé à la longueur de la colonne. */
    public static function text(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)) ?? '';

        return mb_substr(trim($text), 0, $max);
    }
}
