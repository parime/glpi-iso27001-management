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

use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceRules;

/**
 * Issue #866 : suggestion « garder / retirer / à examiner » de chaque compte d'une revue des accès,
 * calculée par des règles explicites (pas d'IA), sans dépendance à GLPI pour être testée
 * unitairement.
 *
 * La suggestion ne décide jamais : elle est affichée avec ses raisons à côté de la décision, que le
 * relecteur prend lui-même. Seule contrainte ajoutée : garder un compte dont la suggestion est
 * « retirer » demande un commentaire (mustJustify()).
 *
 * Signaux utilisés (faits lus par AccessReviewSuggestion) :
 * - retirer : compte supprimé de l'annuaire, date de fin du compte dépassée, départ enregistré
 *   (assetsign) dont la date est passée ;
 * - à examiner : départ ou fin du compte dans les N jours, aucune connexion depuis N jours ou
 *   compte jamais utilisé, changement de groupes sans revue des profils, profil à privilèges
 *   (et, s'il n'a aucune MFA connue, la raison le dit), écart de la fiche de conformité du
 *   collaborateur (#932 : formation obligatoire non suivie ou expirée, charte envoyée mais non
 *   signée, politique non lue, délai dépassé) ;
 * - garder : aucun de ces signaux.
 */
final class AccessReviewSuggestionRules
{
    public const KEEP = 'keep';
    public const REMOVE = 'remove';
    public const EXAMINE = 'examine';

    public const R_DIRECTORY_DELETED = 'directory_deleted';
    public const R_EXPIRED = 'expired';
    public const R_DEPARTED = 'departed';
    public const R_LEAVING = 'leaving';
    public const R_INACTIVE = 'inactive';
    public const R_NEVER_USED = 'never_used';
    public const R_GROUP_CHANGED = 'group_changed';
    public const R_PRIVILEGED = 'privileged';
    public const R_PRIVILEGED_NO_MFA = 'privileged_no_mfa';
    public const R_COMPLIANCE_GAP = 'compliance_gap';
    public const R_NO_SIGNAL = 'no_signal';

    /** Raisons qui conduisent à « retirer ». */
    public const REMOVE_REASONS = [self::R_DIRECTORY_DELETED, self::R_EXPIRED, self::R_DEPARTED];

    /** Durées proposées (0 : règle désactivée). */
    public const INACTIVE_DAYS = [0, 30, 60, 90, 180, 365];
    public const LEAVING_DAYS = [0, 7, 15, 30, 60, 90];

    /**
     * Issue #932 : écarts de la fiche de conformité du collaborateur retenus comme signal
     * (« composante.code » de PersonnelComplianceRules). Seuls les manquements de la personne à
     * une demande réelle comptent : l'absence de toute campagne (aucune formation assignée, aucune
     * charte envoyée) ou une donnée RH jamais saisie (vérification préalable, engagement de
     * confidentialité) n'en sont pas, sinon tous les comptes seraient « à examiner ».
     */
    public const COMPLIANCE_SIGNALS = [
        'training.pending', 'training.renewal_overdue', 'charter.pending', 'policies.pending',
    ];

    /** Fenêtre d'observation d'un changement de groupes. */
    public const GROUP_CHANGE_DAYS = 90;

    /**
     * @return array{inactive_days: int, leaving_days: int, privileged: bool, mfa: bool, group_change: bool,
     *     compliance: bool}
     */
    public static function defaults(): array
    {
        return [
            'inactive_days' => 90,
            'leaving_days'  => 30,
            'privileged'    => true,
            'mfa'           => true,
            'group_change'  => true,
            'compliance'    => true,
        ];
    }

    /**
     * Réglages d'une campagne : valeurs connues gardées, le reste aux valeurs par défaut. Une valeur
     * absente d'un formulaire envoyé (case décochée) se passe à false par l'appelant.
     *
     * @return array{inactive_days: int, leaving_days: int, privileged: bool, mfa: bool, group_change: bool,
     *     compliance: bool}
     */
    public static function normalize(mixed $settings): array
    {
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        $settings = is_array($settings) ? $settings : [];
        $defaults = self::defaults();
        $days = static function (mixed $value, array $allowed, int $default): int {
            return is_numeric($value) && in_array((int) $value, $allowed, true) ? (int) $value : $default;
        };
        $flag = static fn (mixed $value, bool $default): bool => $value === null ? $default : (bool) (int) $value;

        return [
            'inactive_days' => $days(
                $settings['inactive_days'] ?? null,
                self::INACTIVE_DAYS,
                $defaults['inactive_days']
            ),
            'leaving_days'  => $days($settings['leaving_days'] ?? null, self::LEAVING_DAYS, $defaults['leaving_days']),
            'privileged'    => $flag($settings['privileged'] ?? null, $defaults['privileged']),
            'mfa'           => $flag($settings['mfa'] ?? null, $defaults['mfa']),
            'group_change'  => $flag($settings['group_change'] ?? null, $defaults['group_change']),
            'compliance'    => $flag($settings['compliance'] ?? null, $defaults['compliance']),
        ];
    }

    /**
     * Suggestion d'un compte et ses raisons, dans l'ordre : celles qui font « retirer » d'abord.
     *
     * Faits attendus (tous facultatifs) : is_deleted_ldap, end_date, departure_date, last_login,
     * date_creation, privileged_profiles (noms), mfa_level (strong, weak, none ; null si inconnu),
     * group_changed (bool), compliance_gaps (voir complianceGaps()).
     *
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $settings voir normalize()
     * @return array{suggestion: string, reasons: list<array{code: string, params: array<string, mixed>}>}
     */
    public static function suggest(array $facts, array $settings, string $now): array
    {
        $settings = self::normalize($settings);
        $today = substr($now, 0, 10);
        $reasons = [];
        $add = static function (string $code, array $params = []) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'params' => $params];
        };

        if ((int) ($facts['is_deleted_ldap'] ?? 0) === 1) {
            $add(self::R_DIRECTORY_DELETED);
        }
        $end = self::day($facts['end_date'] ?? null);
        $departure = self::day($facts['departure_date'] ?? null);
        if ($end !== null && $end < $today) {
            $add(self::R_EXPIRED, ['date' => $end]);
        }
        if ($departure !== null && $departure < $today) {
            $add(self::R_DEPARTED, ['date' => $departure]);
        }

        // Départ prochain : la plus proche des dates à venir (fin du compte ou départ enregistré).
        if ($settings['leaving_days'] > 0) {
            $horizon = date('Y-m-d', (int) strtotime($today . ' +' . $settings['leaving_days'] . ' days'));
            $upcoming = array_filter(
                [$end, $departure],
                static fn (?string $date): bool => $date !== null && $date >= $today && $date <= $horizon
            );
            if ($upcoming !== []) {
                $add(self::R_LEAVING, ['date' => min($upcoming), 'days' => $settings['leaving_days']]);
            }
        }

        if ($settings['inactive_days'] > 0) {
            $limit = date('Y-m-d H:i:s', (int) strtotime($now . ' -' . $settings['inactive_days'] . ' days'));
            $lastLogin = (string) ($facts['last_login'] ?? '');
            $created = (string) ($facts['date_creation'] ?? '');
            if ($lastLogin !== '') {
                if ($lastLogin < $limit) {
                    $add(self::R_INACTIVE, ['date' => substr($lastLogin, 0, 10), 'days' => $settings['inactive_days']]);
                }
            } elseif ($created !== '' && $created < $limit) {
                $add(self::R_NEVER_USED, ['days' => $settings['inactive_days']]);
            }
        }

        if ($settings['group_change'] && (bool) ($facts['group_changed'] ?? false)) {
            $add(self::R_GROUP_CHANGED, ['days' => self::GROUP_CHANGE_DAYS]);
        }

        if ($settings['compliance']) {
            foreach (is_array($facts['compliance_gaps'] ?? null) ? $facts['compliance_gaps'] : [] as $gap) {
                $signal = is_array($gap) ? ($gap['component'] ?? '') . '.' . ($gap['detail'] ?? '') : '';
                if (in_array($signal, self::COMPLIANCE_SIGNALS, true)) {
                    $add(self::R_COMPLIANCE_GAP, [
                        'component' => (string) $gap['component'],
                        'detail'    => (string) $gap['detail'],
                        'titles'    => array_values(array_map('strval', (array) ($gap['titles'] ?? []))),
                    ]);
                }
            }
        }

        $privileged = array_values(array_filter(
            array_map('strval', is_array($facts['privileged_profiles'] ?? null) ? $facts['privileged_profiles'] : []),
            static fn (string $name): bool => $name !== ''
        ));
        if ($settings['privileged'] && $privileged !== []) {
            $noMfa = $settings['mfa'] && ($facts['mfa_level'] ?? null) === MfaCoverageRules::LEVEL_NONE;
            $add($noMfa ? self::R_PRIVILEGED_NO_MFA : self::R_PRIVILEGED, ['profiles' => $privileged]);
        }

        $codes = array_column($reasons, 'code');
        if (array_intersect($codes, self::REMOVE_REASONS) !== []) {
            $suggestion = self::REMOVE;
        } elseif ($reasons !== []) {
            $suggestion = self::EXAMINE;
        } else {
            $suggestion = self::KEEP;
            $lastLogin = self::day($facts['last_login'] ?? null);
            $add(self::R_NO_SIGNAL, $lastLogin !== null ? ['date' => $lastLogin] : []);
        }

        return ['suggestion' => $suggestion, 'reasons' => $reasons];
    }

    /**
     * Issue #932 : écarts retenus d'une fiche de conformité évaluée (composantes de
     * PersonnelComplianceRules::evaluate()) : composante en retard dont le code figure dans
     * COMPLIANCE_SIGNALS, avec les intitulés concernés (formations, politiques, campagne de charte).
     *
     * @param list<array<string, mixed>> $components
     * @return list<array{component: string, detail: string, titles: list<string>}>
     */
    public static function complianceGaps(array $components): array
    {
        $gaps = [];
        foreach ($components as $component) {
            $key = (string) ($component['key'] ?? '');
            $code = (string) ($component['code'] ?? '');
            if (
                ($component['status'] ?? '') !== PersonnelComplianceRules::LATE
                || !in_array($key . '.' . $code, self::COMPLIANCE_SIGNALS, true)
            ) {
                continue;
            }
            $params = is_array($component['params'] ?? null) ? $component['params'] : [];
            $titles = is_array($params['titles'] ?? null)
                ? $params['titles']
                : (isset($params['campaign']) && $params['campaign'] !== '' ? [$params['campaign']] : []);
            $gaps[] = [
                'component' => $key,
                'detail'    => $code,
                'titles'    => array_values(array_map('strval', $titles)),
            ];
        }

        return $gaps;
    }

    /**
     * Garder un compte que les règles proposent de retirer demande un commentaire.
     */
    public static function mustJustify(string $suggestion, string $decision, string $comment): bool
    {
        return $suggestion === self::REMOVE && $decision === AccessReviewLogic::DECISION_KEEP && trim($comment) === '';
    }

    /**
     * Décision prise à l'opposé de la suggestion (garder un compte à retirer, ou modifier / retirer
     * un compte sans signal) : à repérer dans la revue et le rapport, sans être interdite.
     */
    public static function differs(string $suggestion, string $decision): bool
    {
        return ($suggestion === self::REMOVE && $decision === AccessReviewLogic::DECISION_KEEP)
            || ($suggestion === self::KEEP && AccessReviewLogic::needsTicket($decision));
    }

    /**
     * @param list<string> $suggestions
     * @return array{keep: int, remove: int, examine: int}
     */
    public static function counts(array $suggestions): array
    {
        $counts = [self::REMOVE => 0, self::EXAMINE => 0, self::KEEP => 0];
        foreach ($suggestions as $suggestion) {
            if (isset($counts[$suggestion])) {
                $counts[$suggestion]++;
            }
        }

        return $counts;
    }

    private static function day(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        return substr($value, 0, 10);
    }
}
