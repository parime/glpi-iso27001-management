<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Issue #611 : règles pures de l'approbation des politiques de sécurité par la direction (A.5.1),
 * sur le modèle de celle de la SoA (\GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic).
 * Sans GLPI : testées seules (tests/Unit/Services/Policy/PolicyApprovalRulesTest.php).
 *
 * - Règle de validation : tous les approbateurs (QUORUM_ALL) ou un nombre minimal d'approbations.
 *   Un refus suffit à refuser la version, quelle que soit la règle : la direction a exprimé un
 *   désaccord, le rédacteur reprend sa politique.
 * - Empreinte de CONTENU : titre, version, description et empreintes des documents joints, dans un
 *   ordre canonique. Elle dit si la politique a changé depuis la version approuvée. L'empreinte du
 *   PDF figé (SHA-256 du fichier) est distincte : elle prouve quel document exact a été approuvé.
 * - Une version déjà approuvée ne se soumet pas une seconde fois : toute modification après
 *   approbation passe par une nouvelle version.
 */
final class PolicyApprovalRules
{
    public const VERSION_PENDING   = 'pending';
    public const VERSION_APPROVED  = 'approved';
    public const VERSION_REJECTED  = 'rejected';
    public const VERSION_WITHDRAWN = 'withdrawn';

    public const ANSWER_PENDING  = 'pending';
    public const ANSWER_APPROVED = 'approved';
    public const ANSWER_REJECTED = 'rejected';

    /** Règle « tous les approbateurs ». */
    public const QUORUM_ALL = 0;

    /** Règle de la politique : celle de la configuration. */
    public const QUORUM_DEFAULT = -1;

    public const MAX_QUORUM = 10;

    /** Champs du contenu d'une politique : figés pendant l'approbation et après. */
    public const CONTENT_FIELDS = ['title', 'version', 'description'];

    /**
     * @param array<string, mixed> $policy ligne de la politique
     * @param list<string> $documentHashes empreintes des documents joints (sha1sum de GLPI)
     */
    public static function fingerprint(array $policy, array $documentHashes): string
    {
        $content = [];
        foreach (self::CONTENT_FIELDS as $field) {
            $content[$field] = trim(str_replace("\r\n", "\n", (string) ($policy[$field] ?? '')));
        }
        $hashes = array_values(array_unique(array_map(
            static fn ($hash): string => strtolower(trim((string) $hash)),
            $documentHashes
        )));
        sort($hashes, SORT_STRING);
        $content['documents'] = $hashes;

        return hash('sha256', (string) json_encode($content, JSON_UNESCAPED_UNICODE));
    }

    /** Valeur de règle reçue d'un formulaire : -1 (défaut), 0 (tous) ou un minimum de 1 à 10. */
    public static function sanitizeQuorum(mixed $value, bool $allowDefault = true): int
    {
        if (!is_numeric($value)) {
            return $allowDefault ? self::QUORUM_DEFAULT : self::QUORUM_ALL;
        }
        $quorum = (int) $value;
        if ($quorum === self::QUORUM_DEFAULT) {
            return $allowDefault ? self::QUORUM_DEFAULT : self::QUORUM_ALL;
        }

        return $quorum >= 1 && $quorum <= self::MAX_QUORUM ? $quorum : self::QUORUM_ALL;
    }

    public static function effectiveQuorum(int $policyQuorum, int $defaultQuorum): int
    {
        return $policyQuorum === self::QUORUM_DEFAULT
            ? self::sanitizeQuorum($defaultQuorum, false)
            : self::sanitizeQuorum($policyQuorum, false);
    }

    /** Nombre d'approbations nécessaires pour une version qui a $approverCount approbateurs. */
    public static function requiredApprovals(int $quorum, int $approverCount): int
    {
        return $quorum === self::QUORUM_ALL ? $approverCount : $quorum;
    }

    /**
     * La règle peut-elle être atteinte avec ces approbateurs ?
     */
    public static function isReachable(int $quorum, int $approverCount): bool
    {
        return $approverCount > 0 && self::requiredApprovals($quorum, $approverCount) <= $approverCount;
    }

    /**
     * Statut d'une version d'après les réponses de ses approbateurs et sa règle.
     *
     * @param list<string> $answers
     */
    public static function versionStatus(array $answers, int $quorum): string
    {
        if ($answers === []) {
            return self::VERSION_PENDING;
        }
        if (in_array(self::ANSWER_REJECTED, $answers, true)) {
            return self::VERSION_REJECTED;
        }
        $approved = count(array_filter($answers, static fn (string $a): bool => $a === self::ANSWER_APPROVED));

        return $approved >= self::requiredApprovals($quorum, count($answers))
            ? self::VERSION_APPROVED
            : self::VERSION_PENDING;
    }

    /**
     * Une version de ce libellé a-t-elle déjà été approuvée ? Il faut alors une nouvelle version.
     *
     * @param list<string> $approvedVersions libellés des versions déjà approuvées
     */
    public static function needsNewVersion(string $version, array $approvedVersions): bool
    {
        $version = trim($version);
        foreach ($approvedVersions as $approved) {
            if (strcasecmp(trim($approved), $version) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Champs du contenu que la saisie modifie réellement.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function changedContentFields(array $current, array $input): array
    {
        $changed = [];
        foreach (self::CONTENT_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $before = trim(str_replace("\r\n", "\n", (string) ($current[$field] ?? '')));
            $after = trim(str_replace("\r\n", "\n", (string) $input[$field]));
            if ($before !== $after) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Le contenu est figé : version en attente d'approbation, ou politique approuvée (toute
     * modification passe alors par « Préparer une nouvelle version »).
     */
    public static function isContentLocked(string $policyStatus, bool $hasPendingVersion): bool
    {
        return $hasPendingVersion || $policyStatus === PolicyLifecycle::STATUS_APPROVED;
    }

    /**
     * Situation de la politique approuvée par rapport à sa version approuvée.
     *
     * @return 'not_approved'|'legacy'|'up_to_date'|'changed'
     */
    public static function currentState(string $policyStatus, ?string $approvedFingerprint, string $current): string
    {
        if ($policyStatus !== PolicyLifecycle::STATUS_APPROVED) {
            return 'not_approved';
        }
        if ($approvedFingerprint === null || $approvedFingerprint === '') {
            return 'legacy';
        }

        return hash_equals($approvedFingerprint, $current) ? 'up_to_date' : 'changed';
    }
}
