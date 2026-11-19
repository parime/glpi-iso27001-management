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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

/**
 * Issue #704 : échec d'une lecture d'endoflife.date. Le message est l'un des codes ci-dessous.
 */
final class EndOfLifeException extends \RuntimeException
{
    /** Produit inconnu de la source (404) : correspondance à corriger, résultat gardé un jour. */
    public const NOT_FOUND = 'not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER_ERROR = 'server_error';
    public const UNEXPECTED = 'unexpected';
    public const UNREACHABLE = 'unreachable';

    /** Pas une erreur d'appel : produit non interrogé (limite atteinte ou source injoignable plus tôt). */
    public const SKIPPED = 'skipped';

    public const CODES = [
        self::NOT_FOUND, self::RATE_LIMITED, self::SERVER_ERROR, self::UNEXPECTED, self::UNREACHABLE, self::SKIPPED,
    ];

    /**
     * Code d'erreur d'une réponse HTTP, null pour une réponse 2xx (code 0 : appel refusé ou échoué).
     */
    public static function codeForStatus(int $status): ?string
    {
        return match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 404, $status === 410 => self::NOT_FOUND,
            $status === 429                 => self::RATE_LIMITED,
            $status >= 500                  => self::SERVER_ERROR,
            $status === 0                   => self::UNREACHABLE,
            default                         => self::UNEXPECTED,
        };
    }

    /**
     * Erreur qui arrête les appels de la mise à jour en cours (limite ou panne de la source).
     */
    public static function stopsRefresh(string $code): bool
    {
        return in_array($code, [self::RATE_LIMITED, self::SERVER_ERROR, self::UNREACHABLE], true);
    }
}
