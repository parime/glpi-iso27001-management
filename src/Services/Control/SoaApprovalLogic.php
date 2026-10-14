<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #112 : règles pures de l'approbation de la Déclaration d'Applicabilité par la direction
 * (versions figées, voir PluginGrcmanagerSoaVersion).
 *
 * L'empreinte de CONTENU (fingerprint) ne porte que sur ce qui fait la SoA — pour chaque contrôle :
 * code, applicabilité, état de mise en œuvre, justification — dans un ordre canonique (tri par
 * code, JSON stable). Elle permet de savoir, sans régénérer de PDF, si la SoA a changé depuis la
 * dernière version approuvée : toute modification d'un de ces champs exige une nouvelle version.
 * L'empreinte du PDF figé (SHA-256 du fichier) est distincte : elle prouve quel document exact a
 * été approuvé.
 */
final class SoaApprovalLogic
{
    public const VERSION_PENDING  = 'pending';
    public const VERSION_APPROVED = 'approved';
    public const VERSION_REJECTED = 'rejected';
    public const VERSION_OBSOLETE = 'obsolete';

    public const ANSWER_PENDING  = 'pending';
    public const ANSWER_APPROVED = 'approved';
    public const ANSWER_REJECTED = 'rejected';

    private const CONTENT_FIELDS = ['code', 'applicability', 'implementation_status', 'justification'];

    /**
     * @param iterable<array<string, mixed>> $controlRows lignes de glpi_plugin_grcmanager_controls
     */
    public static function fingerprint(iterable $controlRows): string
    {
        $canonical = [];
        foreach ($controlRows as $row) {
            $entry = [];
            foreach (self::CONTENT_FIELDS as $field) {
                $entry[$field] = trim((string) ($row[$field] ?? ''));
            }
            $canonical[$entry['code']] = $entry;
        }
        uksort($canonical, 'strnatcmp');

        return hash('sha256', (string) json_encode(array_values($canonical), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Statut d'une version d'après les réponses de ses approbateurs : un refus suffit à la refuser,
     * il faut l'accord de tous pour l'approuver.
     *
     * @param list<string> $answers
     */
    public static function versionStatus(array $answers): string
    {
        if (in_array(self::ANSWER_REJECTED, $answers, true)) {
            return self::VERSION_REJECTED;
        }
        if ($answers !== [] && array_unique($answers) === [self::ANSWER_APPROVED]) {
            return self::VERSION_APPROVED;
        }

        return self::VERSION_PENDING;
    }

    /**
     * Situation de la SoA actuelle par rapport à la dernière version approuvée.
     *
     * @return 'never_approved'|'up_to_date'|'changed'
     */
    public static function currentState(?string $lastApprovedFingerprint, string $currentFingerprint): string
    {
        if ($lastApprovedFingerprint === null) {
            return 'never_approved';
        }

        return hash_equals($lastApprovedFingerprint, $currentFingerprint) ? 'up_to_date' : 'changed';
    }
}
