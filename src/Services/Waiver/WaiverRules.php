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

namespace GlpiPlugin\Grcmanager\Services\Waiver;

/**
 * Issue #170 : règles pures du registre des dérogations de sécurité (exceptions temporaires à une
 * politique ou à une mesure, pour des actifs donnés).
 *
 * Le statut est saisi par l'équipe (demandée, validée, refusée, close) ; l'état affiché en découle
 * avec la date de fin (active, bientôt échue, échue). Rien n'est prolongé ni clos automatiquement.
 */
final class WaiverRules
{
    /** Alerte du responsable avant la fin de la dérogation, en jours. */
    public const WARNING_DAYS = 15;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CLOSED    = 'closed';

    public const STATUSES = [self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CLOSED];

    public const STATE_ACTIVE   = 'active';
    public const STATE_EXPIRING = 'expiring';
    public const STATE_EXPIRED  = 'expired';

    /**
     * État affiché : le statut saisi, sauf pour une dérogation validée, dont l'état dépend de la
     * date de fin (incluse : une dérogation « jusqu'au 10 » vaut encore le 10).
     */
    public static function state(string $status, ?string $dateEnd, string $today): string
    {
        if ($status !== self::STATUS_APPROVED) {
            return in_array($status, self::STATUSES, true) ? $status : self::STATUS_REQUESTED;
        }
        if ($dateEnd === null || $dateEnd === '') {
            return self::STATE_ACTIVE;
        }
        if ($dateEnd < $today) {
            return self::STATE_EXPIRED;
        }
        $warningFrom = date('Y-m-d', (int) strtotime($dateEnd . ' -' . self::WARNING_DAYS . ' days'));

        return $today >= $warningFrom ? self::STATE_EXPIRING : self::STATE_ACTIVE;
    }

    /** Responsable à prévenir : dérogation validée bientôt échue, pas encore signalée. */
    public static function reminderDue(string $status, ?string $dateEnd, ?string $notifiedAt, string $today): bool
    {
        return self::state($status, $dateEnd, $today) === self::STATE_EXPIRING
            && ($notifiedAt === null || $notifiedAt === '');
    }

    /** Ticket de réévaluation : une seule fois, une fois la dérogation validée échue. */
    public static function ticketDue(string $status, ?string $dateEnd, int $ticketsId, string $today): bool
    {
        return $ticketsId === 0 && self::state($status, $dateEnd, $today) === self::STATE_EXPIRED;
    }

    /**
     * Erreurs de saisie : un nom, et une date de fin postérieure ou égale au début. Une dérogation
     * est par nature temporaire : la date de fin est obligatoire.
     *
     * @return list<string>
     */
    public static function validate(string $name, ?string $dateBegin, ?string $dateEnd): array
    {
        $errors = [];
        if (trim($name) === '') {
            $errors[] = 'name_required';
        }
        if ($dateEnd === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $dateEnd) !== 1) {
            $errors[] = 'date_end_required';
        } elseif ($dateBegin !== null && $dateBegin !== '' && substr($dateEnd, 0, 10) < substr($dateBegin, 0, 10)) {
            $errors[] = 'date_end_before_begin';
        }

        return $errors;
    }
}
