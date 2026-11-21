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

namespace GlpiPlugin\Grcmanager\Services\Access;

/**
 * Issue #835 : lecture d'un rapport XML de PingCastle (ad_hc_<domaine>.xml, racine
 * HealthcheckData). Le plugin ne lance pas PingCastle et n'interroge pas l'annuaire : il lit le
 * fichier que l'équipe a produit.
 *
 * Sécurité :
 * - taille limitée (MAX_BYTES) ;
 * - un document qui déclare un DOCTYPE ou une entité est refusé (XXE, expansion d'entités) ; la
 *   lecture se fait sans accès réseau (LIBXML_NONET) et sans substitution d'entités ;
 * - seuls sont lus le domaine, la date, la version, les scores et, pour chaque règle déclenchée,
 *   son identifiant, sa catégorie, son modèle, ses points et son constat (Rationale). Le détail des
 *   comptes, des machines et les mots de passe trouvés (GPP, par exemple) ne sont jamais repris ;
 *   leur présence est seulement signalée (secrets) ;
 * - sanitize() rend une copie du rapport dont tous les éléments de mot de passe ou de secret sont
 *   vidés, pour l'archive restreinte.
 */
final class PingCastleReport
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** Règles lues au plus dans un rapport. */
    public const MAX_RULES = 1000;

    /** Longueur gardée du constat d'une règle. */
    public const MAX_RATIONALE = 1000;

    public const ERROR_EMPTY = 'empty';
    public const ERROR_TOO_LARGE = 'too_large';
    public const ERROR_DOCTYPE = 'doctype';
    public const ERROR_INVALID = 'invalid';
    public const ERROR_NOT_PINGCASTLE = 'not_pingcastle';

    /** Noms d'éléments qui portent un secret (mot de passe GPP, secret, hachage). */
    private const SECRET_NAME = '/(pass(word|wd)?|pwd|secret|hash)$/i';

    /**
     * @return array{error: string, tool: string, domain: string, netbios: string, report_date: ?string,
     *     engine_version: string, maturity_level: ?int, scores: array{global: int, stale: ?int,
     *     privileged: ?int, trust: ?int, anomaly: ?int}, rules: list<array{rule_id: string,
     *     category: string, model: string, points: int, rationale: string}>, secrets: bool,
     *     truncated: bool}
     */
    public static function parse(string $content): array
    {
        $result = [
            'error'          => '',
            'tool'           => AdAuditRules::TOOL_PINGCASTLE,
            'domain'         => '',
            'netbios'        => '',
            'report_date'    => null,
            'engine_version' => '',
            'maturity_level' => null,
            'scores'         => [
                'global' => 0, 'stale' => null, 'privileged' => null, 'trust' => null, 'anomaly' => null,
            ],
            'rules'          => [],
            'secrets'        => false,
            'truncated'      => false,
        ];
        $xml = self::load($content, $result['error']);
        if ($xml === null) {
            return $result;
        }
        if ($xml->getName() !== 'HealthcheckData' || !isset($xml->GlobalScore)) {
            $result['error'] = self::ERROR_NOT_PINGCASTLE;

            return $result;
        }

        $result['domain'] = mb_strtolower(self::text($xml->DomainFQDN, 255));
        $result['netbios'] = self::text($xml->NetBIOSName, 64);
        $result['engine_version'] = self::text($xml->EngineVersion, 64);
        $result['report_date'] = self::date(self::text($xml->GenerationDate, 64));
        $result['maturity_level'] = self::intOrNull($xml->MaturityLevel);
        $result['scores'] = [
            'global'     => AdAuditRules::clampScore(self::intOrNull($xml->GlobalScore) ?? 0),
            'stale'      => self::score($xml->StaleObjectsScore),
            // Orthographe de PingCastle (« Privilegied »), et la forme correcte au cas où.
            'privileged' => self::score($xml->PrivilegiedGroupScore) ?? self::score($xml->PrivilegedGroupScore),
            'trust'      => self::score($xml->TrustScore),
            'anomaly'    => self::score($xml->AnomalyScore),
        ];

        foreach ($xml->RiskRules->HealthcheckRiskRule ?? [] as $rule) {
            if (count($result['rules']) >= self::MAX_RULES) {
                $result['truncated'] = true;
                break;
            }
            $ruleId = self::text($rule->RiskId, 128);
            if ($ruleId === '') {
                continue;
            }
            $result['rules'][] = [
                'rule_id'   => $ruleId,
                'category'  => AdAuditRules::normalizeCategory(self::text($rule->Category, 64)),
                'model'     => self::text($rule->Model, 128),
                'points'    => max(0, self::intOrNull($rule->Points) ?? 0),
                'rationale' => self::text($rule->Rationale, self::MAX_RATIONALE),
            ];
        }
        $result['secrets'] = self::hasSecrets($content);

        return $result;
    }

    /**
     * Copie du rapport dont les éléments de mot de passe ou de secret sont vidés ; null si le
     * fichier ne se lit pas (même contrôles que parse()).
     */
    public static function sanitize(string $content): ?string
    {
        $error = '';
        if (self::load($content, $error) === null) {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $elements = [];
        foreach ($dom->getElementsByTagName('*') as $element) {
            // Éléments feuilles seulement : une section (GPPPassword) garde sa structure.
            if (
                $element->childElementCount === 0
                && preg_match(self::SECRET_NAME, $element->localName ?? '') === 1
                && trim($element->textContent) !== ''
            ) {
                $elements[] = $element;
            }
        }
        foreach ($elements as $element) {
            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($dom->createTextNode('[removed]'));
        }
        $xml = $dom->saveXML();

        return $xml === false ? null : $xml;
    }

    /**
     * Le rapport contient-il un mot de passe ou un secret non vide (mot de passe GPP, par exemple) ?
     */
    public static function hasSecrets(string $content): bool
    {
        return preg_match('/<(\w*(?:pass(?:word|wd)?|pwd|secret|hash))(\s[^>]*)?>\s*[^<\s]/i', $content) === 1;
    }

    /**
     * Contrôles communs : taille, DOCTYPE et entités refusés, XML bien formé.
     */
    private static function load(string $content, string &$error): ?\SimpleXMLElement
    {
        if (trim($content) === '') {
            $error = self::ERROR_EMPTY;

            return null;
        }
        if (strlen($content) > self::MAX_BYTES) {
            $error = self::ERROR_TOO_LARGE;

            return null;
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) === 1) {
            $error = self::ERROR_DOCTYPE;

            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            $error = self::ERROR_INVALID;

            return null;
        }

        return $xml;
    }

    private static function text(?\SimpleXMLElement $node, int $max): string
    {
        if ($node === null) {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $node) ?? '');

        return mb_substr($text, 0, $max);
    }

    private static function intOrNull(?\SimpleXMLElement $node): ?int
    {
        if ($node === null) {
            return null;
        }
        $value = trim((string) $node);

        return preg_match('/^-?\d{1,9}$/', $value) === 1 ? (int) $value : null;
    }

    private static function score(?\SimpleXMLElement $node): ?int
    {
        $value = self::intOrNull($node);

        return $value === null ? null : AdAuditRules::clampScore($value);
    }

    /**
     * Date ISO 8601 de PingCastle (2026-03-14T10:22:31.1234567+01:00) en date SQL, ou null.
     */
    public static function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        // Fractions de seconde à plus de six chiffres : DateTimeImmutable n'en lit que six.
        $value = preg_replace('/(\.\d{6})\d+/', '$1', $value) ?? $value;
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        $year = (int) $date->format('Y');

        return $year < 2000 || $year > 2100 ? null : $date->format('Y-m-d H:i:s');
    }
}
