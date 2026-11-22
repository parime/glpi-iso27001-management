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

namespace GlpiPlugin\Grcmanager\Services\Audit;

/**
 * Issue #881 : préparer l'audit du cycle suivant à partir d'un audit passé. Règles pures, sans
 * GLPI : champs repris, titre et date proposés, type d'audit suivant, décalage des échéances.
 *
 * Un audit recopié repart toujours de zéro : statut « Planifié », sans date de réalisation, sans
 * conclusion, sans constat, sans pièce ni résultat de grille. Seuls la préparation (périmètre,
 * objectifs, critères, demandes, questions) et les personnes sont reprises.
 */
final class DuplicationRules
{
    public const PART_SCOPE = 'scope';
    public const PART_PEOPLE = 'people';
    public const PART_EVIDENCE = 'evidence';
    public const PART_CHECKLIST = 'checklist';

    public const PARTS = [self::PART_SCOPE, self::PART_PEOPLE, self::PART_EVIDENCE, self::PART_CHECKLIST];

    /** Colonne de l'audit qui garde la trace de l'audit d'origine. */
    public const SOURCE_FIELD = 'plugin_grcmanager_audits_id_source';

    /** Champs de préparation repris tels quels (ceux qui existent sur l'audit d'origine). */
    private const COPIED_FIELDS = [
        'objectives', 'scope', 'criteria', 'audit_type', 'suppliers_id', 'lead_auditor',
        'certification_standard', 'entities_id', 'is_recursive',
    ];

    /** Champs repris avec les personnes. */
    private const PEOPLE_FIELDS = ['users_id', 'users_id_responsible'];

    /** Champs repris avec le périmètre. */
    private const SCOPE_FIELDS = ['risk_categories'];

    /** Cycle de certification : l'audit qui suit chaque type d'audit de l'organisme. */
    private const NEXT_TYPE = [
        'initial'        => 'surveillance_1',
        'surveillance_1' => 'surveillance_2',
        'surveillance_2' => 'renewal',
        'renewal'        => 'surveillance_1',
    ];

    public const MAX_TITLE_LENGTH = 255;

    /**
     * Parties cochées, dans l'ordre de PARTS ; valeurs inconnues ignorées.
     *
     * @param array<mixed> $parts
     * @return list<string>
     */
    public static function parts(array $parts): array
    {
        $wanted = array_map('strval', array_filter($parts, 'is_scalar'));

        return array_values(array_intersect(self::PARTS, $wanted));
    }

    /** Type de l'audit suivant : un audit interne reste interne ; l'organisme suit son cycle. */
    public static function nextAuditType(string $type): string
    {
        return self::NEXT_TYPE[$type] ?? CertificationAuditRules::TYPE_INTERNAL;
    }

    /** Date au format AAAA-MM-JJ valide, sinon null. */
    public static function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date !== false && $date->format('Y-m-d') === substr($value, 0, 10) ? $date->format('Y-m-d') : null;
    }

    /** Date proposée : un an après la date prévue de l'audit d'origine, sinon un an après aujourd'hui. */
    public static function defaultPlannedDate(?string $plannedDate, string $today): string
    {
        $base = self::parseDate($plannedDate) ?? self::parseDate($today) ?? date('Y-m-d');

        return (new \DateTimeImmutable($base))->modify('+1 year')->format('Y-m-d');
    }

    /**
     * Titre proposé : l'année de l'audit d'origine remplacée par la nouvelle, sinon la nouvelle
     * année ajoutée au titre.
     */
    public static function defaultTitle(string $title, ?string $oldDate, string $newDate): string
    {
        $title = trim($title);
        $newYear = substr($newDate, 0, 4);
        $oldYear = self::parseDate($oldDate) !== null ? substr((string) $oldDate, 0, 4) : null;
        if ($oldYear !== null && $oldYear !== $newYear && preg_match('/\b' . $oldYear . '\b/', $title) === 1) {
            $result = (string) preg_replace('/\b' . $oldYear . '\b/', $newYear, $title);
        } elseif (preg_match('/\b' . $newYear . '\b/', $title) === 1) {
            $result = $title;
        } else {
            $result = $title === '' ? $newYear : $title . ' - ' . $newYear;
        }

        return mb_substr($result, 0, self::MAX_TITLE_LENGTH);
    }

    /** Titre saisi, nettoyé ; vide si rien de lisible. */
    public static function cleanTitle(mixed $title): string
    {
        return is_scalar($title) ? mb_substr(trim((string) $title), 0, self::MAX_TITLE_LENGTH) : '';
    }

    /**
     * Échéance décalée d'autant que la date de l'audit ; null si une date manque.
     */
    public static function shiftDate(?string $date, ?string $from, ?string $to): ?string
    {
        $date = self::parseDate($date);
        $from = self::parseDate($from);
        $to = self::parseDate($to);
        if ($date === null || $from === null || $to === null) {
            return null;
        }
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');

        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Champs du nouvel audit : préparation reprise, statut « Planifié », rien de la réalisation.
     *
     * @param array<string, mixed> $source  ligne de l'audit d'origine
     * @param list<string>         $parts   parties reprises (parts())
     * @param array<string, mixed> $choices title, planned_date, audit_type, suppliers_id, lead_auditor
     * @return array<string, mixed>
     */
    public static function auditInput(array $source, array $parts, array $choices): array
    {
        $fields = self::COPIED_FIELDS;
        if (in_array(self::PART_PEOPLE, $parts, true)) {
            array_push($fields, ...self::PEOPLE_FIELDS);
        }
        if (in_array(self::PART_SCOPE, $parts, true)) {
            array_push($fields, ...self::SCOPE_FIELDS);
        }
        $input = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $source)) {
                $input[$field] = $source[$field];
            }
        }
        $input['title'] = self::cleanTitle($choices['title'] ?? '');
        $input['planned_date'] = self::parseDate($choices['planned_date'] ?? null);
        $input['status'] = 'planned';
        $input[self::SOURCE_FIELD] = (int) ($source['id'] ?? 0);
        if (array_key_exists('audit_type', $source)) {
            $wanted = (string) ($choices['audit_type'] ?? '');
            $type = CertificationAuditRules::normalizeAuditType(
                $wanted !== '' ? $wanted : (string) $source['audit_type']
            );
            $input['audit_type'] = $type;
            if (CertificationAuditRules::isExternal($type)) {
                foreach (['suppliers_id', 'lead_auditor'] as $field) {
                    if (array_key_exists($field, $choices)) {
                        $input[$field] = $field === 'suppliers_id'
                            ? max(0, (int) $choices[$field])
                            : self::cleanTitle($choices[$field]);
                    }
                }
            }
        }

        return $input;
    }
}
