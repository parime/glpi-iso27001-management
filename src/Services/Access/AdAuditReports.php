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
 * Issue #835, lot 2 : reconnaissance de l'outil qui a produit un fichier importé sur la page de
 * l'audit de sécurité, et lecture par le lecteur de cet outil :
 * - XML : PingCastle (PingCastleReport) ;
 * - JSON : Maester ou ScubaGear (CloudPostureReport) ;
 * - CSV : Purple Knight (PurpleKnightReport).
 *
 * Tous les lecteurs rendent la même forme (emptyResult()).
 */
final class AdAuditReports
{
    public const ERROR_UNKNOWN_FORMAT = 'unknown_format';

    /**
     * @return array<string, mixed>
     */
    public static function emptyResult(string $tool): array
    {
        return [
            'error'          => '',
            'tool'           => $tool,
            'domain'         => '',
            'netbios'        => '',
            'report_date'    => null,
            'engine_version' => '',
            'maturity_level' => null,
            'scores'         => [
                'global' => 0, 'stale' => null, 'privileged' => null, 'trust' => null, 'anomaly' => null,
            ],
            'native_score'   => null,
            'checks_total'   => 0,
            'checks_passed'  => 0,
            'rules'          => [],
            'secrets'        => false,
            'truncated'      => false,
        ];
    }

    /**
     * Lit un fichier de l'un des outils reconnus.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $content): array
    {
        $text = self::toUtf8($content);
        if ($text === null) {
            return ['error' => PingCastleReport::ERROR_INVALID] + self::emptyResult('');
        }
        $start = ltrim($text)[0] ?? '';

        return match (true) {
            $start === ''  => ['error' => PingCastleReport::ERROR_EMPTY] + self::emptyResult(''),
            $start === '<' => PingCastleReport::parse($text) + [
                'native_score' => null, 'checks_total' => 0, 'checks_passed' => 0,
            ],
            $start === '{' || $start === '[' => CloudPostureReport::parse($text),
            default        => self::csv($text),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function csv(string $text): array
    {
        $result = PurpleKnightReport::parse($text);
        if ($result['error'] === PurpleKnightReport::ERROR_NOT_PURPLEKNIGHT) {
            $result['error'] = self::ERROR_UNKNOWN_FORMAT;
        }

        return $result;
    }

    /**
     * Contenu en UTF-8 sans marque d'ordre des octets (les exports Windows sont souvent en UTF-16
     * ou commencent par une BOM UTF-8) ; null s'il ne se convertit pas.
     */
    public static function toUtf8(string $content): ?string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $encoding = str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $converted = mb_convert_encoding(substr($content, 2), 'UTF-8', $encoding);
            $content = is_string($converted) ? $converted : '';
            if ($content === '') {
                return null;
            }
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
            if (!is_string($converted)) {
                return null;
            }
            $content = $converted;
        }

        return $content;
    }
}
