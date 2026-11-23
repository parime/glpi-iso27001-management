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

namespace GlpiPlugin\Grcmanager\Services\Scanner;

use DOMElement;
use DOMNode;
use XMLReader;

/**
 * Issue #543 : rapport XML de Greenbone Community Edition / OpenVAS (format de rapport « XML »,
 * identifiant a994b278-1f62-11e1-96ac-406186ea4fc5, ou « Anonymous XML »), téléchargé depuis
 * l'interface (Rapports > Télécharger) ou obtenu par la commande get_reports du protocole GMP.
 *
 * Sources officielles suivies :
 * - protocole GMP, réponse de get_reports (éléments report, results/result, host, nvt, refs/ref,
 *   severity, threat, qod, scan_start, scan_end) : https://docs.greenbone.net/API/GMP/gmp-22.4.html#command_get_reports
 * - rapport réel publié par Greenbone (GMP 22.8) :
 *   https://github.com/greenbone/gvm-report-service/blob/main/scripts/report.xml
 * - anciennes versions (OMP 7 / GMP 8) : CVE dans nvt/cve, séparées par des virgules, « NOCVE » si
 *   aucune : https://docs.greenbone.net/API/GMP/gmp-8.0.html#command_get_reports
 *
 * Seuls les résultats de gravité positive sont gardés : « Log » (0), faux positifs (-1) et erreurs
 * de test (-3, bloc errors) ne sont pas des constats. Le bloc host du rapport donne les hôtes
 * analysés, même sans constat. Lecture en flux (XMLReader), sans réseau (LIBXML_NONET), sans DTD ni
 * substitution d'entités ; chaque résultat est déplié seul (quelques Ko).
 */
final class OpenVasReport implements ScannerReportParser
{
    public const SCANNER = 'openvas';

    /** Format de rapport « XML » de Greenbone. */
    public const FORMAT_XML = 'a994b278-1f62-11e1-96ac-406186ea4fc5';

    private const MAX_SOLUTION = 2000;

    private const MAX_CVES = 50;

    public static function scanner(): string
    {
        return self::SCANNER;
    }

    public static function label(): string
    {
        return 'Greenbone / OpenVAS';
    }

    public static function accepts(string $head): bool
    {
        if (preg_match('/<(get_reports_response|report)[\s>]/', $head) !== 1) {
            return false;
        }

        return str_contains($head, self::FORMAT_XML) || str_contains($head, '<gmp>')
            || str_contains($head, '<omp>') || str_contains($head, '<scan_nvt_version>');
    }

    public static function parseFile(string $path): array
    {
        $reader = new XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!@$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                return self::failure(ScannerReports::ERROR_UNREADABLE);
            }
            $result = self::read($reader);
            $reader->close();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $result;
    }

    /**
     * Lit un contenu XML en mémoire (tests).
     *
     * @return array<string, mixed>
     */
    public static function parseString(string $xml): array
    {
        if ($xml === '') {
            return self::failure(ScannerReports::ERROR_UNREADABLE);
        }
        if (ScannerReports::hasDoctype(substr($xml, 0, ScannerReports::HEAD_BYTES))) {
            return self::failure(ScannerReports::ERROR_DOCTYPE);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
            if (!$reader instanceof XMLReader) {
                return self::failure(ScannerReports::ERROR_INVALID);
            }
            $result = self::read($reader);
            $reader->close();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(string $error): array
    {
        return [
            'error'     => $error,
            'report'    => ['uuid' => '', 'task' => '', 'scan_start' => '', 'scan_end' => ''],
            'hosts'     => [],
            'findings'  => [],
            'skipped'   => 0,
            'truncated' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(XMLReader $reader): array
    {
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        $out = self::failure(ScannerReports::ERROR_NONE);
        $seenReport = false;
        /** @var list<string> $stack noms des éléments ouverts */
        $stack = [];
        $ok = @$reader->read();
        while ($ok) {
            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                array_pop($stack);
                $ok = @$reader->read();
                continue;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                $ok = @$reader->read();
                continue;
            }
            $name = $reader->localName;
            $parent = $stack === [] ? '' : $stack[count($stack) - 1];
            if ($stack === [] && !in_array($name, ['report', 'get_reports_response'], true)) {
                return self::failure(ScannerReports::ERROR_INVALID);
            }
            if ($name === 'report' && !$seenReport) {
                $seenReport = true;
                $out['report']['uuid'] = ScannerReports::text((string) $reader->getAttribute('id'), 64);
            }
            $expanded = true;
            if ($name === 'result' && $parent === 'results') {
                if (count($out['findings']) >= ScannerReports::MAX_FINDINGS) {
                    $out['truncated'] = true;
                } else {
                    $node = $reader->expand();
                    $finding = $node instanceof DOMElement ? self::finding($node) : null;
                    if ($finding === null) {
                        $out['skipped']++;
                    } else {
                        $out['findings'][] = $finding;
                        $out['hosts'][$finding['host']] ??= $finding['hostname'];
                    }
                }
            } elseif ($name === 'host' && $parent === 'report') {
                $node = $reader->expand();
                if ($node instanceof DOMElement) {
                    self::host($node, $out['hosts']);
                }
            } elseif ($parent === 'report' && in_array($name, ['scan_start', 'scan_end'], true)) {
                $out['report'][$name] = ScannerReports::date((string) $reader->readString());
            } elseif ($parent === 'report' && $name === 'task' && $out['report']['task'] === '') {
                $node = $reader->expand();
                if ($node instanceof DOMElement) {
                    $out['report']['task'] = ScannerReports::text(self::childText($node, 'name'));
                }
            } else {
                $expanded = false;
            }
            if ($expanded) {
                // Élément lu en entier : passer à son frère sans descendre dedans.
                $ok = @$reader->next();
                continue;
            }
            if (!$reader->isEmptyElement) {
                $stack[] = $name;
            }
            $ok = @$reader->read();
        }
        if (!$seenReport) {
            return self::failure(ScannerReports::ERROR_INVALID);
        }
        if (libxml_get_last_error() !== false && $out['findings'] === [] && $out['hosts'] === []) {
            return self::failure(ScannerReports::ERROR_INVALID);
        }

        return $out;
    }

    /**
     * Constat d'un élément result, null pour un résultat d'information.
     *
     * @return array<string, mixed>|null
     */
    private static function finding(DOMElement $result): ?array
    {
        $severity = (float) str_replace(',', '.', trim(self::childText($result, 'severity')));
        $threat = trim(self::childText($result, 'threat'));
        if ($severity <= 0.0 || in_array($threat, ['Log', 'False Positive', 'Debug', 'Error'], true)) {
            return null;
        }
        $hostNode = self::child($result, 'host');
        $host = '';
        $hostname = '';
        if ($hostNode !== null) {
            foreach ($hostNode->childNodes as $part) {
                if ($part->nodeType === XML_TEXT_NODE && trim((string) $part->nodeValue) !== '') {
                    $host = trim((string) $part->nodeValue);
                    break;
                }
            }
            $hostname = self::childText($hostNode, 'hostname');
        }
        $host = ScannerReports::normalizeHost(ScannerReports::text($host, 255));
        $hostname = ScannerReports::normalizeHost(ScannerReports::text($hostname, 255));
        if ($host === '') {
            $host = $hostname;
        }
        if ($host === '') {
            return null;
        }
        $nvt = self::child($result, 'nvt');
        $testId = $nvt === null ? '' : (string) $nvt->getAttribute('oid');
        $name = self::childText($result, 'name');
        if ($name === '' && $nvt !== null) {
            $name = self::childText($nvt, 'name');
        }
        $cves = [];
        $solution = '';
        $solutionType = '';
        $family = '';
        if ($nvt !== null) {
            $family = self::childText($nvt, 'family');
            $refs = self::child($nvt, 'refs');
            if ($refs !== null) {
                foreach ($refs->childNodes as $ref) {
                    if (
                        $ref instanceof DOMElement && $ref->localName === 'ref'
                        && strtolower($ref->getAttribute('type')) === 'cve'
                    ) {
                        $cves[] = $ref->getAttribute('id');
                    }
                }
            }
            // OMP 7 / GMP 8 : liste « CVE-…, CVE-… » dans nvt/cve.
            foreach (explode(',', self::childText($nvt, 'cve')) as $cve) {
                $cves[] = $cve;
            }
            $solutionNode = self::child($nvt, 'solution');
            if ($solutionNode !== null) {
                $solution = (string) $solutionNode->textContent;
                $solutionType = $solutionNode->getAttribute('type');
            }
            $tags = self::childText($nvt, 'tags');
            if ($solutionType === '' && preg_match('/(?:^|\|)solution_type=([A-Za-z]+)/', $tags, $match) === 1) {
                $solutionType = $match[1];
            }
        }
        $cves = array_values(array_unique(array_filter(
            array_map(static fn (string $cve): string => strtoupper(trim($cve)), $cves),
            static fn (string $cve): bool => preg_match('/^CVE-\d{4}-\d{4,}$/', $cve) === 1
        )));
        $qod = self::child($result, 'qod');

        return [
            'host'          => $host,
            'hostname'      => $hostname,
            'port'          => ScannerReports::text(self::childText($result, 'port'), 64),
            'test_id'       => ScannerReports::text($testId, 128),
            'name'          => ScannerReports::text($name),
            'family'        => ScannerReports::text($family, 128),
            'severity'      => round(min(10.0, $severity), 1),
            'qod'           => $qod === null ? null : max(0, min(100, (int) self::childText($qod, 'value'))),
            'cves'          => array_slice($cves, 0, self::MAX_CVES),
            'solution_type' => ScannerReports::text($solutionType, 32),
            'solution'      => ScannerReports::text($solution, self::MAX_SOLUTION, true),
        ];
    }

    /**
     * Hôte analysé (bloc host du rapport) : adresse et nom d'hôte relevé par le test « Host Details ».
     *
     * @param array<string, string> $hosts
     */
    private static function host(DOMElement $node, array &$hosts): void
    {
        $ip = ScannerReports::normalizeHost(ScannerReports::text(self::childText($node, 'ip')));
        $hostname = '';
        foreach ($node->childNodes as $detail) {
            if (
                $detail instanceof DOMElement && $detail->localName === 'detail'
                && trim(self::childText($detail, 'name')) === 'hostname'
            ) {
                $hostname = ScannerReports::normalizeHost(ScannerReports::text(self::childText($detail, 'value')));
                break;
            }
        }
        $key = $ip !== '' ? $ip : $hostname;
        if ($key === '') {
            return;
        }
        if (($hosts[$key] ?? '') === '') {
            $hosts[$key] = $hostname;
        }
    }

    private static function child(DOMNode $node, string $name): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private static function childText(DOMNode $node, string $name): string
    {
        $child = self::child($node, $name);

        return $child === null ? '' : (string) $child->textContent;
    }
}
