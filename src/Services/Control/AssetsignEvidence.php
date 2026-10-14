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

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #109 : preuves d'audit automatiques tirées du plugin assetsign (fiches de remise et de
 * restitution signées) pour les contrôles A.5.9 (inventaire), A.5.10 (utilisation correcte) et
 * A.5.11 (restitution des actifs).
 *
 * Aucune dépendance dure : assetsign est détecté à l'exécution (plugin actif ET table présente),
 * et la lecture se fait directement sur sa table, en lecture seule, sans charger ses classes. Les
 * valeurs de type/statut ci-dessous recopient donc celles de
 * GlpiPlugin\Assetsign\Assetsign::TYPE_* / STATUS_* — elles sont stockées en base et figées par
 * assetsign (les numéros retirés y sont volontairement laissés vides, jamais réutilisés).
 *
 * Seuls des chiffres agrégés sortent d'ici (jamais de nom de bénéficiaire ni de matériel) : un
 * utilisateur qui lit la SoA sans droit sur assetsign voit les compteurs, pas les fiches (le lien
 * vers la liste n'est affiché qu'avec le droit de lecture assetsign, voir
 * AssetsignEvidenceProvider::listUrl()). Cette classe-ci est du PHP pur (testée unitairement,
 * analysée par PHPStan) ; tout ce qui lit GLPI vit dans AssetsignEvidenceProvider.
 */
final class AssetsignEvidence
{
    public const PLUGIN_KEY = 'assetsign';
    public const TABLE      = 'glpi_plugin_assetsign_assetsigns';
    public const RIGHTNAME  = 'plugin_assetsign_assetsign';

    public const TYPE_HANDOVER = 0;
    public const TYPE_RETURN   = 1;

    private const STATUS_PENDING              = 1;
    private const STATUS_SENT                 = 2;
    private const STATUS_VIEWED               = 3;
    private const STATUS_SIGNED               = 4;
    private const STATUS_EXPIRED              = 6;
    private const STATUS_AWAITING_COSIGNATURE = 9;

    /** Contrôle Annexe A => types de fiche assetsign qui en constituent la preuve. */
    public const CONTROL_TYPES = [
        'A.5.9'  => [self::TYPE_HANDOVER],
        'A.5.10' => [self::TYPE_HANDOVER],
        'A.5.11' => [self::TYPE_RETURN],
    ];

    public static function isRelevantControl(string $code): bool
    {
        return isset(self::CONTROL_TYPES[$code]);
    }

    /**
     * Range un statut assetsign dans l'un des trois compteurs exposés à l'auditeur. Brouillon (0),
     * annulée (7) et types hors périmètre ne comptent pas : ce ne sont ni des preuves ni des
     * engagements en cours.
     */
    public static function bucketForStatus(int $status): ?string
    {
        return match ($status) {
            self::STATUS_SIGNED => 'signed',
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_VIEWED,
            self::STATUS_AWAITING_COSIGNATURE => 'pending',
            self::STATUS_EXPIRED => 'expired',
            default => null,
        };
    }

    /**
     * @param iterable<array{type: int|string, status: int|string, cpt: int|string}> $rows
     *        résultat d'un GROUP BY type, status
     * @return array<int, array{signed: int, pending: int, expired: int}> indexé par type
     */
    public static function summarize(iterable $rows): array
    {
        $empty = ['signed' => 0, 'pending' => 0, 'expired' => 0];
        $summary = [self::TYPE_HANDOVER => $empty, self::TYPE_RETURN => $empty];

        foreach ($rows as $row) {
            $type = (int) $row['type'];
            $bucket = self::bucketForStatus((int) $row['status']);
            if ($bucket === null || !isset($summary[$type])) {
                continue;
            }
            $summary[$type][$bucket] += (int) $row['cpt'];
        }

        return $summary;
    }
}
