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

/**
 * Issue #543 : formats de rapports de scanners reconnus à l'import et règles communes (gravité,
 * clé d'un constat, normalisation des hôtes). Fonctions pures, sans base ni session.
 *
 * Formats payants (Nessus, Qualys, Rapid7 InsightVM) : issue #841, priorité basse ; ils ne sont
 * ajoutés qu'avec un rapport d'exemple officiel publié par l'éditeur.
 */
final class ScannerReports
{
    public const ERROR_NONE = '';
    public const ERROR_UNREADABLE = 'unreadable';
    public const ERROR_TOO_LARGE = 'too_large';
    public const ERROR_DOCTYPE = 'doctype';
    public const ERROR_INVALID = 'invalid';
    public const ERROR_UNKNOWN_FORMAT = 'unknown_format';

    /** Taille maximale d'un rapport lu. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /** Constats lus au plus dans un rapport. */
    public const MAX_FINDINGS = 20000;

    /** Octets lus pour reconnaître le format et refuser une DTD. */
    public const HEAD_BYTES = 65536;

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    /** Niveaux, du plus grave au moins grave. */
    public const SEVERITIES = [
        self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW,
    ];

    /**
     * @return list<class-string<ScannerReportParser>>
     */
    public static function parsers(): array
    {
        return [OpenVasReport::class];
    }

    /**
     * Nom de l'outil d'un identifiant enregistré, l'identifiant lui-même s'il n'est plus connu.
     */
    public static function label(string $scanner): string
    {
        foreach (self::parsers() as $parser) {
            if ($parser::scanner() === $scanner) {
                return $parser::label();
            }
        }

        return $scanner;
    }

    /**
     * Reconnaît le format puis lit le fichier.
     *
     * @return array{error: string, scanner: string, report: array<string, string>,
     *     hosts: array<string, string>, findings: list<array<string, mixed>>, skipped: int, truncated: bool}
     */
    public static function parseFile(string $path): array
    {
        $empty = [
            'error' => self::ERROR_UNREADABLE, 'scanner' => '', 'report' => [], 'hosts' => [], 'findings' => [],
            'skipped' => 0, 'truncated' => false,
        ];
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            return $empty;
        }
        if ($size > self::MAX_BYTES) {
            return ['error' => self::ERROR_TOO_LARGE] + $empty;
        }
        $handle = fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, self::HEAD_BYTES);
        if ($handle !== false) {
            fclose($handle);
        }
        if (self::hasDoctype($head)) {
            return ['error' => self::ERROR_DOCTYPE] + $empty;
        }
        foreach (self::parsers() as $parser) {
            if ($parser::accepts($head)) {
                return ['scanner' => $parser::scanner()] + $parser::parseFile($path);
            }
        }

        return ['error' => self::ERROR_UNKNOWN_FORMAT] + $empty;
    }

    public static function hasDoctype(string $head): bool
    {
        return stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false;
    }

    /**
     * Niveau d'un score CVSS (0 à 10), seuils de la spécification CVSS v3.1
     * (https://www.first.org/cvss/v3.1/specification-document, section 5), qui sont aussi ceux des
     * niveaux Critical, High, Medium et Low de Greenbone ; null pour un score nul (information).
     */
    public static function severityLevel(float $score): ?string
    {
        return match (true) {
            $score >= 9.0 => self::SEVERITY_CRITICAL,
            $score >= 7.0 => self::SEVERITY_HIGH,
            $score >= 4.0 => self::SEVERITY_MEDIUM,
            $score > 0.0  => self::SEVERITY_LOW,
            default       => null,
        };
    }

    /**
     * Clé stable d'un constat : même outil, même hôte, même port, même test. Rejouer un rapport, ou
     * importer l'analyse suivante, met à jour le constat au lieu d'en créer un autre.
     */
    public static function findingKey(string $scanner, string $host, string $port, string $testId): string
    {
        return hash('sha256', implode("\n", [$scanner, self::normalizeHost($host), mb_strtolower(trim($port)), trim($testId)]));
    }

    /**
     * Adresse ou nom d'hôte ramené à une forme comparable (minuscules, sans point final).
     */
    public static function normalizeHost(string $host): string
    {
        return rtrim(mb_strtolower(trim($host)), '.');
    }

    /**
     * Texte d'un rapport sans caractère de contrôle, au plus $max caractères ; sur une ligne, ou en
     * gardant les sauts de ligne ($multiline).
     */
    public static function text(string $value, int $max = 255, bool $multiline = false): string
    {
        if ($multiline) {
            $value = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', str_replace("\r", '', $value));
            $value = trim((string) preg_replace("/\n{3,}/u", "\n\n", $value));
        } else {
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Date ISO 8601 d'un rapport, ramenée au fuseau du serveur et au format de la base, '' si illisible.
     */
    public static function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return '';
        }
        $year = (int) $date->format('Y');
        if ($year < 2000 || $year > 2100) {
            return '';
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * Nombre de constats par niveau.
     *
     * @param iterable<array<string, mixed>> $findings
     * @return array<string, int> niveau => nombre, plus « total »
     */
    public static function countBySeverity(iterable $findings): array
    {
        $counts = array_fill_keys(self::SEVERITIES, 0) + ['total' => 0];
        foreach ($findings as $finding) {
            $level = self::severityLevel((float) ($finding['severity'] ?? 0));
            if ($level !== null) {
                $counts[$level]++;
                $counts['total']++;
            }
        }

        return $counts;
    }
}
