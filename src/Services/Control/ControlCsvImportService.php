<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Validation pure de l'import CSV de la SoA (ROADMAP.md "Version 1.5") — ne touche jamais la base
 * ni le système de fichiers, seulement des tableaux déjà lus (par `front/control.csv.php` via
 * `fgetcsv()`). Applique exactement la même règle que
 * `PluginGrcmanagerControl::validateAndMarkReviewed()` (justification obligatoire si
 * l'applicabilité n'est pas pleinement "yes") pour que l'import ne puisse jamais produire un état
 * qu'une sauvegarde manuelle normale n'accepterait pas.
 *
 * Traite chaque ligne indépendamment : une ligne invalide est rejetée avec un code de raison
 * stable (traduit par l'appelant, cette classe n'a aucune dépendance GLPI — voir
 * `REASON_*` ci-dessous), sans empêcher les autres lignes valides d'être appliquées — jamais un
 * import silencieusement partiel, jamais une création de contrôle (catalogue fixe, voir
 * `PluginGrcmanagerControl::showForm()`, `candel=false`) : un code absent du catalogue existant
 * est un rejet, pas une nouvelle ligne.
 */
final class ControlCsvImportService
{
    public const REASON_UNKNOWN_CODE = 'unknown_code';
    public const REASON_UNKNOWN_APPLICABILITY = 'unknown_applicability';
    public const REASON_UNKNOWN_STATUS = 'unknown_status';
    public const REASON_MISSING_JUSTIFICATION = 'missing_justification';

    /**
     * @param list<array<string, string>> $rows Chaque ligne attendue avec les clés "code",
     *     "applicabilite", "etat_mise_en_oeuvre", "justification" (une clé manquante est traitée
     *     comme une chaîne vide, jamais une erreur PHP).
     * @param list<string> $existingCodes Codes réels déjà présents dans le catalogue (ex. "A.5.1").
     * @param array<string, string> $applicabilityLabelsByKey Ex. ['yes' => 'Applicable', ...].
     * @param array<string, string> $statusLabelsByKey Ex. ['not_started' => 'Non démarré', ...].
     * @return array{
     *     valid: list<array{
     *         code: string, applicability: string, implementation_status: string, justification: string
     *     }>,
     *     rejected: list<array{code: string, reason: string}>
     * }
     */
    public static function process(
        array $rows,
        array $existingCodes,
        array $applicabilityLabelsByKey,
        array $statusLabelsByKey
    ): array {
        $existingCodesLookup = array_flip($existingCodes);
        $applicabilityKeysByLabel = array_flip($applicabilityLabelsByKey);
        $statusKeysByLabel = array_flip($statusLabelsByKey);

        $valid = [];
        $rejected = [];

        foreach ($rows as $row) {
            $code = trim($row['code'] ?? '');

            if ($code === '' || !isset($existingCodesLookup[$code])) {
                $rejected[] = ['code' => $code, 'reason' => self::REASON_UNKNOWN_CODE];
                continue;
            }

            $applicabilityLabel = trim($row['applicabilite'] ?? '');
            if (!isset($applicabilityKeysByLabel[$applicabilityLabel])) {
                $rejected[] = ['code' => $code, 'reason' => self::REASON_UNKNOWN_APPLICABILITY];
                continue;
            }

            $statusLabel = trim($row['etat_mise_en_oeuvre'] ?? '');
            if (!isset($statusKeysByLabel[$statusLabel])) {
                $rejected[] = ['code' => $code, 'reason' => self::REASON_UNKNOWN_STATUS];
                continue;
            }

            $applicability = $applicabilityKeysByLabel[$applicabilityLabel];
            $justification = trim($row['justification'] ?? '');

            if (in_array($applicability, ['no', 'partial'], true) && $justification === '') {
                $rejected[] = ['code' => $code, 'reason' => self::REASON_MISSING_JUSTIFICATION];
                continue;
            }

            $valid[] = [
                'code'                  => $code,
                'applicability'         => $applicability,
                'implementation_status' => $statusKeysByLabel[$statusLabel],
                'justification'         => $justification,
            ];
        }

        return ['valid' => $valid, 'rejected' => $rejected];
    }
}
