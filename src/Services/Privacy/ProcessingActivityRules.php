<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Privacy;

/**
 * Issue #375 : règles du registre des activités de traitement (RGPD, article 30), sans dépendance
 * à GLPI (testées unitairement). Normalisation des saisies (rôle, base légale, listes de
 * catégories) et contrôles de complétude d'une fiche : ce qui manque pour que le registre réponde
 * à l'article 30 ou appelle une décision (analyse d'impact, garanties d'un transfert hors UE).
 *
 * Les libellés sont traduits par l'appelant (\GlpiPlugin\Grcmanager\ProcessingActivity) : ce
 * service ne porte que les clés.
 */
final class ProcessingActivityRules
{
    /** Rôle de l'organisme : responsable, responsable conjoint ou sous-traitant (art. 30.1 et 30.2). */
    public const ROLES = ['controller', 'joint_controller', 'processor'];
    public const DEFAULT_ROLE = 'controller';

    /** Bases légales de l'article 6.1 (a à f). */
    public const LEGAL_BASES = [
        'consent',
        'contract',
        'legal_obligation',
        'vital_interests',
        'public_task',
        'legitimate_interests',
    ];

    public const STATUSES = ['draft', 'active', 'archived'];
    public const DEFAULT_STATUS = 'draft';

    public const DATA_SUBJECTS = [
        'employees',
        'candidates',
        'customers',
        'prospects',
        'supplier_contacts',
        'website_visitors',
        'patients',
        'students',
        'minors',
        'other',
    ];

    public const DATA_CATEGORIES = [
        'identity',
        'personal_life',
        'professional_life',
        'financial',
        'connection',
        'location',
        'images_voice',
        'other',
    ];

    /** Données sensibles (art. 9), infractions (art. 10), numéro d'identification national (art. 87). */
    public const SENSITIVE_DATA = [
        'racial_ethnic',
        'political',
        'religious',
        'trade_union',
        'genetic',
        'biometric',
        'health',
        'sex_life',
        'criminal',
        'national_id',
    ];

    /** Garanties d'un transfert hors de l'Union européenne (articles 45 à 49). */
    public const TRANSFER_SAFEGUARDS = ['adequacy', 'scc', 'bcr', 'derogation', 'other'];

    /** Analyse d'impact (article 35) : décision prise sur la fiche, l'analyse elle-même vient plus tard. */
    public const DPIA_STATUSES = ['not_assessed', 'not_required', 'required', 'done'];
    public const DEFAULT_DPIA_STATUS = 'not_assessed';

    /** Revue du registre : une fiche active non revue depuis plus longtemps est signalée. */
    public const REVIEW_MONTHS = 12;

    public const CHECK_NO_PURPOSE = 'no_purpose';
    public const CHECK_NO_LEGAL_BASIS = 'no_legal_basis';
    public const CHECK_NO_DATA_SUBJECTS = 'no_data_subjects';
    public const CHECK_NO_DATA_CATEGORIES = 'no_data_categories';
    public const CHECK_NO_RETENTION = 'no_retention';
    public const CHECK_NO_SECURITY = 'no_security';
    public const CHECK_NO_CONTROLLER = 'no_controller';
    public const CHECK_TRANSFER_WITHOUT_SAFEGUARD = 'transfer_without_safeguard';
    public const CHECK_SENSITIVE_WITHOUT_DPIA = 'sensitive_without_dpia';
    public const CHECK_DPIA_PENDING = 'dpia_pending';
    public const CHECK_REVIEW_OVERDUE = 'review_overdue';

    /** Écarts qui rendent la fiche non conforme à l'article 30 (les autres appellent une décision). */
    private const BLOCKING = [
        self::CHECK_NO_PURPOSE,
        self::CHECK_NO_LEGAL_BASIS,
        self::CHECK_NO_DATA_SUBJECTS,
        self::CHECK_NO_DATA_CATEGORIES,
        self::CHECK_NO_RETENTION,
        self::CHECK_NO_CONTROLLER,
        self::CHECK_TRANSFER_WITHOUT_SAFEGUARD,
    ];

    public static function normalizeRole(mixed $value): string
    {
        return self::oneOf($value, self::ROLES, self::DEFAULT_ROLE);
    }

    public static function normalizeStatus(mixed $value): string
    {
        return self::oneOf($value, self::STATUSES, self::DEFAULT_STATUS);
    }

    /** Base légale connue, ou chaîne vide (pas encore déterminée). */
    public static function normalizeLegalBasis(mixed $value): string
    {
        return self::oneOf($value, self::LEGAL_BASES, '');
    }

    /** Garantie connue, ou chaîne vide. */
    public static function normalizeSafeguard(mixed $value): string
    {
        return self::oneOf($value, self::TRANSFER_SAFEGUARDS, '');
    }

    public static function normalizeDpiaStatus(mixed $value): string
    {
        return self::oneOf($value, self::DPIA_STATUSES, self::DEFAULT_DPIA_STATUS);
    }

    /**
     * Liste soumise (cases multiples) ramenée aux clés connues, dans l'ordre du catalogue, sans
     * doublon, puis encodée en JSON pour la base.
     *
     * @param list<string> $catalog
     */
    public static function encodeList(mixed $values, array $catalog): string
    {
        $values = is_array($values) ? array_map('strval', $values) : [];

        return (string) json_encode(array_values(array_intersect($catalog, $values)));
    }

    /**
     * Liste lue en base (JSON), limitée aux clés connues.
     *
     * @param list<string> $catalog
     * @return list<string>
     */
    public static function decodeList(mixed $json, array $catalog): array
    {
        $values = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_intersect($catalog, array_map('strval', $values)));
    }

    /**
     * Écarts de la fiche. Une fiche archivée n'est plus contrôlée.
     *
     * @param array<string, mixed> $fields      champs de la fiche (listes en JSON)
     * @param bool                 $hasControls au moins une mesure de la SoA liée
     * @return list<string>
     */
    public static function checks(array $fields, string $today, bool $hasControls = false): array
    {
        if (($fields['status'] ?? self::DEFAULT_STATUS) === 'archived') {
            return [];
        }
        $blank = static fn (string $key): bool => trim((string) ($fields[$key] ?? '')) === '';

        $checks = [];
        if ($blank('purpose')) {
            $checks[] = self::CHECK_NO_PURPOSE;
        }
        if (self::normalizeLegalBasis($fields['legal_basis'] ?? '') === '') {
            $checks[] = self::CHECK_NO_LEGAL_BASIS;
        }
        if (self::decodeList($fields['data_subjects'] ?? '', self::DATA_SUBJECTS) === []) {
            $checks[] = self::CHECK_NO_DATA_SUBJECTS;
        }
        $sensitive = self::decodeList($fields['sensitive_data'] ?? '', self::SENSITIVE_DATA);
        if (self::decodeList($fields['data_categories'] ?? '', self::DATA_CATEGORIES) === [] && $sensitive === []) {
            $checks[] = self::CHECK_NO_DATA_CATEGORIES;
        }
        if ($blank('retention_period')) {
            $checks[] = self::CHECK_NO_RETENTION;
        }
        if ($blank('security_measures') && !$hasControls) {
            $checks[] = self::CHECK_NO_SECURITY;
        }
        // Sous-traitant ou responsable conjoint : le registre nomme le responsable pour qui il agit.
        if (self::normalizeRole($fields['role'] ?? '') !== 'controller' && $blank('controller_name')) {
            $checks[] = self::CHECK_NO_CONTROLLER;
        }
        $safeguard = self::normalizeSafeguard($fields['transfer_safeguards'] ?? '');
        if ((int) ($fields['transfers_outside_eu'] ?? 0) === 1 && $safeguard === '') {
            $checks[] = self::CHECK_TRANSFER_WITHOUT_SAFEGUARD;
        }
        $dpia = self::normalizeDpiaStatus($fields['dpia_status'] ?? '');
        if ($sensitive !== [] && $dpia === 'not_assessed') {
            $checks[] = self::CHECK_SENSITIVE_WITHOUT_DPIA;
        }
        if ($dpia === 'required') {
            $checks[] = self::CHECK_DPIA_PENDING;
        }
        $active = ($fields['status'] ?? '') === 'active';
        if ($active && self::isReviewOverdue($fields['last_review_date'] ?? null, $today)) {
            $checks[] = self::CHECK_REVIEW_OVERDUE;
        }

        return $checks;
    }

    public static function isBlocking(string $check): bool
    {
        return in_array($check, self::BLOCKING, true);
    }

    /** Jamais revue, ou dernière revue il y a plus de REVIEW_MONTHS mois. */
    public static function isReviewOverdue(mixed $lastReviewDate, string $today): bool
    {
        $last = is_string($lastReviewDate) ? strtotime(substr($lastReviewDate, 0, 10)) : false;
        if ($last === false) {
            return true;
        }

        return date('Y-m-d', (int) strtotime('+' . self::REVIEW_MONTHS . ' months', $last)) < $today;
    }

    /**
     * Cellule d'export CSV : un texte saisi commençant par = + - @ (ou tabulation, retour) serait
     * exécuté comme formule par le tableur ; il est préfixé d'une apostrophe.
     */
    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
