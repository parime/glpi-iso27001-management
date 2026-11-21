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

use XMLReader;

/**
 * Issue #781 : lecture des résultats XCCDF (1.1 et 1.2) d'OpenSCAP, seuls ou dans un rapport ARF
 * (asset reporting format), sans dépendance à GLPI.
 *
 * Lecture durcie :
 * - lecture en flux (XMLReader) : un rapport ARF de plusieurs dizaines de mégaoctets n'est jamais
 *   chargé en entier en mémoire ;
 * - toute déclaration de type de document (DOCTYPE) est refusée avant le premier élément : aucune
 *   entité, ni interne (expansion exponentielle) ni externe (XXE), n'est jamais résolue ;
 * - aucun accès réseau (LIBXML_NONET), pas de DTD chargée ni validée, pas d'XInclude ;
 * - taille du fichier, nombre de résultats, de règles et longueur des textes bornés.
 *
 * Seules les règles en échec ou en erreur sont gardées avec leur titre ; les autres sont comptées.
 */
final class XccdfParser
{
    /** Taille maximale d'un fichier importé (octets). Un rapport ARF complet dépasse rarement 50 Mo. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /** Résultats (TestResult) gardés au plus par fichier. */
    public const MAX_TEST_RESULTS = 50;

    /** Règles en échec gardées au plus par résultat. */
    public const MAX_FAILED_RULES = 3000;

    /** Titres de règles et de profils mémorisés au plus. */
    private const MAX_TITLES = 30000;

    /** Début du fichier examiné avant la lecture XML (prologue). */
    private const PROLOG_BYTES = 65536;

    /** Longueur maximale d'un texte gardé (titre, identifiant). */
    private const MAX_TEXT = 255;

    public const ERROR_NONE = '';
    public const ERROR_UNREADABLE = 'unreadable';
    public const ERROR_TOO_LARGE = 'too_large';
    public const ERROR_DOCTYPE = 'doctype';
    public const ERROR_INVALID = 'invalid';
    public const ERROR_NO_RESULT = 'no_result';

    /** Résultats XCCDF reconnus (les autres valeurs sont comptées comme « autre »). */
    public const RESULTS = [
        'pass', 'fail', 'error', 'unknown', 'notapplicable', 'notchecked', 'notselected', 'informational', 'fixed',
    ];

    /** Résultats gardés en détail. */
    public const FAILED_RESULTS = ['fail', 'error'];

    /** Gravités XCCDF. */
    public const SEVERITIES = ['high', 'medium', 'low', 'info', 'unknown'];

    /**
     * Lit un fichier XCCDF ou ARF.
     *
     * @return array{error: string, reports: list<array<string, mixed>>, truncated: bool}
     */
    public static function parseFile(string $path): array
    {
        $empty = ['error' => self::ERROR_UNREADABLE, 'reports' => [], 'truncated' => false];
        if (!is_file($path) || !is_readable($path)) {
            return $empty;
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return $empty;
        }
        if ($size > self::MAX_BYTES) {
            return ['error' => self::ERROR_TOO_LARGE, 'reports' => [], 'truncated' => false];
        }
        $handle = fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, self::PROLOG_BYTES);
        if ($handle !== false) {
            fclose($handle);
        }
        if (self::hasDoctype($head)) {
            return ['error' => self::ERROR_DOCTYPE, 'reports' => [], 'truncated' => false];
        }
        $reader = new XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            // Ni entités substituées, ni DTD chargée ou validée, ni réseau.
            if (!@$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                return $empty;
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
     * Lit un contenu XML en mémoire (tests, petits fichiers).
     *
     * @return array{error: string, reports: list<array<string, mixed>>, truncated: bool}
     */
    public static function parseString(string $xml): array
    {
        if ($xml === '') {
            return ['error' => self::ERROR_UNREADABLE, 'reports' => [], 'truncated' => false];
        }
        if (strlen($xml) > self::MAX_BYTES) {
            return ['error' => self::ERROR_TOO_LARGE, 'reports' => [], 'truncated' => false];
        }
        if (self::hasDoctype(substr($xml, 0, self::PROLOG_BYTES))) {
            return ['error' => self::ERROR_DOCTYPE, 'reports' => [], 'truncated' => false];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
            if (!$reader instanceof XMLReader) {
                return ['error' => self::ERROR_INVALID, 'reports' => [], 'truncated' => false];
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
     * @return array{error: string, reports: list<array<string, mixed>>, truncated: bool}
     */
    private static function read(XMLReader $reader): array
    {
        libxml_clear_errors();
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        /** @var array<string, string> $ruleTitles */
        $ruleTitles = [];
        /** @var array<string, string> $ruleSeverities */
        $ruleSeverities = [];
        /** @var array<string, string> $profileTitles */
        $profileTitles = [];
        $benchmarkTitle = '';
        $benchmarkId = '';
        $assetNames = [];
        $reports = [];
        $truncated = false;
        $seenRoot = false;

        /** @var list<string> $stack noms locaux des éléments ouverts */
        $stack = [];
        $currentRule = '';
        $currentProfile = '';
        $current = null;
        $currentRuleResult = null;

        while (true) {
            $ok = @$reader->read();
            if (!$ok) {
                break;
            }
            $type = $reader->nodeType;
            if ($type === XMLReader::DOC_TYPE) {
                // Refus avant tout élément : rien n'est substitué ni chargé.
                return ['error' => self::ERROR_DOCTYPE, 'reports' => [], 'truncated' => false];
            }
            if ($type === XMLReader::ENTITY_REF || $type === XMLReader::ENTITY) {
                return ['error' => self::ERROR_DOCTYPE, 'reports' => [], 'truncated' => false];
            }
            if ($type === XMLReader::END_ELEMENT) {
                $name = array_pop($stack);
                if ($name === 'Rule') {
                    $currentRule = '';
                } elseif ($name === 'Profile') {
                    $currentProfile = '';
                } elseif ($name === 'rule-result' && $current !== null && $currentRuleResult !== null) {
                    self::closeRuleResult($current, $currentRuleResult, $truncated);
                    $currentRuleResult = null;
                } elseif ($name === 'TestResult' && $current !== null) {
                    $reports[] = $current;
                    $current = null;
                }
                continue;
            }
            if ($type !== XMLReader::ELEMENT) {
                continue;
            }
            $seenRoot = true;
            $name = $reader->localName;
            $parent = $stack === [] ? '' : $stack[count($stack) - 1];
            if (!$reader->isEmptyElement) {
                $stack[] = $name;
            }

            if ($name === 'Benchmark' && $benchmarkId === '') {
                $benchmarkId = self::text((string) $reader->getAttribute('id'));
            } elseif ($name === 'Rule') {
                $currentRule = self::text((string) $reader->getAttribute('id'));
                $severity = strtolower((string) $reader->getAttribute('severity'));
                if (
                    $currentRule !== '' && in_array($severity, self::SEVERITIES, true)
                    && count($ruleSeverities) < self::MAX_TITLES
                ) {
                    $ruleSeverities[$currentRule] = $severity;
                }
            } elseif ($name === 'Profile') {
                $currentProfile = self::text((string) $reader->getAttribute('id'));
            } elseif ($name === 'title') {
                if ($parent === 'Rule' && $currentRule !== '' && !isset($ruleTitles[$currentRule])) {
                    if (count($ruleTitles) < self::MAX_TITLES) {
                        $ruleTitles[$currentRule] = self::text(self::readText($reader));
                    }
                } elseif ($parent === 'Profile' && $currentProfile !== '' && !isset($profileTitles[$currentProfile])) {
                    if (count($profileTitles) < self::MAX_TITLES) {
                        $profileTitles[$currentProfile] = self::text(self::readText($reader));
                    }
                } elseif ($parent === 'Benchmark' && $benchmarkTitle === '') {
                    $benchmarkTitle = self::text(self::readText($reader));
                } elseif ($parent === 'TestResult' && $current !== null && $current['title'] === '') {
                    $current['title'] = self::text(self::readText($reader));
                }
            } elseif ($name === 'TestResult') {
                if (count($reports) >= self::MAX_TEST_RESULTS) {
                    // Résultats suivants ignorés : leur contenu est parcouru sans rien garder.
                    $truncated = true;
                    $current = null;
                    continue;
                }
                $current = self::newReport($reader);
                if ($reader->isEmptyElement) {
                    $reports[] = $current;
                    $current = null;
                }
            } elseif ($current !== null) {
                self::readTestResultChild($reader, $name, $parent, $current, $currentRuleResult);
            } elseif ($name === 'fqdn' || $name === 'hostname') {
                // ARF : nom de l'actif décrit hors du résultat (ai:computing-device).
                $assetNames[$name] ??= self::text(self::readText($reader));
            }
        }

        if (!$seenRoot) {
            return ['error' => self::ERROR_INVALID, 'reports' => [], 'truncated' => false];
        }
        // Fichier mal formé ou coupé : rien n'est importé, pas même les premiers résultats.
        $lastError = libxml_get_last_error();
        if ($lastError !== false && $lastError->level >= LIBXML_ERR_ERROR) {
            return ['error' => self::ERROR_INVALID, 'reports' => [], 'truncated' => false];
        }
        if ($reports === []) {
            return ['error' => self::ERROR_NO_RESULT, 'reports' => [], 'truncated' => false];
        }

        foreach ($reports as &$report) {
            if ($report['benchmark_id'] === '') {
                $report['benchmark_id'] = $benchmarkId;
            }
            $report['benchmark_title'] = $benchmarkTitle;
            $report['profile_title'] = $profileTitles[$report['profile_id']] ?? '';
            if ($report['hostname'] === '') {
                $report['hostname'] = $assetNames['hostname'] ?? '';
            }
            if ($report['fqdn'] === '') {
                $report['fqdn'] = $assetNames['fqdn'] ?? '';
            }
            foreach ($report['failed'] as &$rule) {
                $rule['title'] = $ruleTitles[$rule['rule_id']] ?? '';
                if ($rule['severity'] === 'unknown' && isset($ruleSeverities[$rule['rule_id']])) {
                    $rule['severity'] = $ruleSeverities[$rule['rule_id']];
                }
            }
            unset($rule);
            $report['high_failed'] = count(array_filter(
                $report['failed'],
                static fn (array $rule): bool => $rule['severity'] === 'high'
            ));
        }
        unset($report);

        return ['error' => self::ERROR_NONE, 'reports' => $reports, 'truncated' => $truncated];
    }

    /**
     * @return array<string, mixed>
     */
    private static function newReport(XMLReader $reader): array
    {
        return [
            'test_id'      => self::text((string) $reader->getAttribute('id')),
            'title'        => '',
            'scanner'      => self::text((string) $reader->getAttribute('test-system')),
            'start_time'   => self::date((string) $reader->getAttribute('start-time')),
            'end_time'     => self::date((string) $reader->getAttribute('end-time')),
            'benchmark_id' => '',
            'profile_id'   => '',
            'target'       => '',
            'hostname'     => '',
            'fqdn'         => '',
            'addresses'    => [],
            'score'        => null,
            'score_max'    => null,
            'counts'       => array_fill_keys([...self::RESULTS, 'other'], 0),
            'failed'       => [],
            'failed_truncated' => false,
        ];
    }

    /**
     * @param array<string, mixed>      $current
     * @param array<string, mixed>|null $ruleResult
     */
    private static function readTestResultChild(
        XMLReader $reader,
        string $name,
        string $parent,
        array &$current,
        ?array &$ruleResult
    ): void {
        switch ($name) {
            case 'benchmark':
                $id = (string) $reader->getAttribute('id');
                if ($id === '') {
                    $href = (string) $reader->getAttribute('href');
                    $id = str_contains($href, '#') ? substr($href, (int) strrpos($href, '#') + 1) : '';
                }
                $current['benchmark_id'] = self::text($id);
                break;
            case 'profile':
                $current['profile_id'] = self::text((string) $reader->getAttribute('idref'));
                break;
            case 'target':
                if ($parent === 'TestResult' && $current['target'] === '') {
                    $current['target'] = self::text(self::readText($reader));
                }
                break;
            case 'target-address':
                if (count($current['addresses']) < 10) {
                    $address = self::text(self::readText($reader));
                    if ($address !== '') {
                        $current['addresses'][] = $address;
                    }
                }
                break;
            case 'fact':
                $factName = (string) $reader->getAttribute('name');
                if ($factName === 'urn:xccdf:fact:asset:identifier:fqdn' && $current['fqdn'] === '') {
                    $current['fqdn'] = self::text(self::readText($reader));
                } elseif ($factName === 'urn:xccdf:fact:asset:identifier:host_name' && $current['hostname'] === '') {
                    $current['hostname'] = self::text(self::readText($reader));
                }
                break;
            case 'rule-result':
                $severity = strtolower((string) $reader->getAttribute('severity'));
                $ruleResult = [
                    'rule_id'  => self::text((string) $reader->getAttribute('idref')),
                    'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown',
                    'result'   => '',
                ];
                if ($reader->isEmptyElement) {
                    $truncated = false;
                    self::closeRuleResult($current, $ruleResult, $truncated);
                    $ruleResult = null;
                }
                break;
            case 'result':
                if ($parent === 'rule-result' && $ruleResult !== null) {
                    $ruleResult['result'] = strtolower(trim(self::readText($reader)));
                }
                break;
            case 'score':
                if ($parent === 'TestResult' && $current['score'] === null) {
                    $system = (string) $reader->getAttribute('system');
                    if ($system === '' || $system === 'urn:xccdf:scoring:default') {
                        $value = trim(self::readText($reader));
                        $current['score'] = is_numeric($value) ? round((float) $value, 2) : null;
                        $max = (string) $reader->getAttribute('maximum');
                        $current['score_max'] = is_numeric($max) ? round((float) $max, 2) : 100.0;
                    }
                }
                break;
        }
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $ruleResult
     */
    private static function closeRuleResult(array &$current, array $ruleResult, bool &$truncated): void
    {
        $result = (string) $ruleResult['result'];
        $key = in_array($result, self::RESULTS, true) ? $result : 'other';
        $current['counts'][$key]++;
        if (!in_array($result, self::FAILED_RESULTS, true) || $ruleResult['rule_id'] === '') {
            return;
        }
        if (count($current['failed']) >= self::MAX_FAILED_RULES) {
            $current['failed_truncated'] = true;
            $truncated = true;

            return;
        }
        $current['failed'][] = $ruleResult;
    }

    /**
     * Déclaration de type ou d'entité dans le début du fichier : refusée avant toute lecture XML. Une
     * déclaration plus loin est refusée par la lecture elle-même (nœud DOCTYPE ou fichier invalide).
     */
    private static function hasDoctype(string $head): bool
    {
        return stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false;
    }

    /**
     * Texte d'un élément simple, sans descendre dans un gros contenu : au plus quelques Ko.
     */
    private static function readText(XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        return mb_substr((string) $reader->readString(), 0, 4 * self::MAX_TEXT);
    }

    private static function text(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        // Caractères de contrôle retirés (ils ne servent jamais dans un nom ou un titre).
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return mb_substr($value, 0, self::MAX_TEXT);
    }

    /**
     * Date ISO 8601 du rapport, ramenée au format de la base, ou '' si illisible.
     */
    private static function date(string $value): string
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
        // Les horaires du rapport sont gardés dans le fuseau du serveur GLPI.
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
