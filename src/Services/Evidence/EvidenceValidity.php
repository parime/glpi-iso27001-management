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

namespace GlpiPlugin\Grcmanager\Services\Evidence;

/**
 * Issue #145 : règles pures (sans GLPI) de validité des documents de preuve — un certificat ISO
 * fournisseur, un rapport de pentest ou une attestation d'assurance expirés ne prouvent plus rien.
 * Aucune décision ici ne change un statut : la tâche planifiée prévient le responsable, puis ouvre
 * un ticket « Renouveler la preuve » (PluginGrcmanagerEvidence::cronEvidencereminder()).
 */
final class EvidenceValidity
{
    /** Délai d'alerte avant l'expiration, en jours. */
    public const WARNING_DAYS = 30;

    public const STATUS_NONE     = 'none';
    public const STATUS_VALID    = 'valid';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED  = 'expired';

    /**
     * Éléments du plugin dont les documents peuvent porter une date de validité.
     */
    public const ITEMTYPES = [
        'PluginGrcmanagerControl',
        'PluginGrcmanagerSupplierRisk',
        'PluginGrcmanagerAudit',
        'PluginGrcmanagerPolicy',
    ];

    /**
     * La date de validité est incluse : un document valide « jusqu'au 10 » prouve encore le 10.
     */
    public static function status(?string $validUntil, string $today, int $warningDays = self::WARNING_DAYS): string
    {
        if ($validUntil === null || $validUntil === '') {
            return self::STATUS_NONE;
        }
        if ($validUntil < $today) {
            return self::STATUS_EXPIRED;
        }
        $warningFrom = date('Y-m-d', (int) strtotime($validUntil . ' -' . max(0, $warningDays) . ' days'));

        return $today >= $warningFrom ? self::STATUS_EXPIRING : self::STATUS_VALID;
    }

    /** Responsable à prévenir : la preuve arrive à échéance et il ne l'a pas encore été. */
    public static function reminderDue(?string $validUntil, ?string $notifiedAt, string $today): bool
    {
        return self::status($validUntil, $today) === self::STATUS_EXPIRING
            && ($notifiedAt === null || $notifiedAt === '');
    }

    /** Ticket de renouvellement : une seule fois, une fois la preuve expirée. */
    public static function ticketDue(?string $validUntil, int $ticketsId, string $today): bool
    {
        return $ticketsId === 0 && self::status($validUntil, $today) === self::STATUS_EXPIRED;
    }

    public static function isSupportedItemtype(string $itemtype): bool
    {
        return in_array($itemtype, self::ITEMTYPES, true);
    }
}
