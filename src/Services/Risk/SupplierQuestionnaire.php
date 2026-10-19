<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #127 : questionnaire de sécurité d'un fournisseur et son score.
 *
 * Chaque question reçoit « oui » (1 point), « partiel » (0,5), « non » (0) ou « sans objet »
 * (exclue du calcul). Le score est le pourcentage de points obtenus sur les questions applicables ;
 * il éclaire l'évaluation du risque fournisseur sans jamais modifier d'office la probabilité,
 * l'impact ou le niveau de risque saisis par l'équipe. Logique pure, testée directement.
 */
final class SupplierQuestionnaire
{
    public const ANSWER_YES = 'yes';
    public const ANSWER_PARTIAL = 'partial';
    public const ANSWER_NO = 'no';
    public const ANSWER_NA = 'na';

    /** @var array<string, float> réponse => points */
    private const POINTS = [self::ANSWER_YES => 1.0, self::ANSWER_PARTIAL => 0.5, self::ANSWER_NO => 0.0];

    /** Clés des questions, dans l'ordre d'affichage (libellés : PluginGrcmanagerSupplierAssessment). */
    public const QUESTIONS = [
        'certification',
        'security_policy',
        'data_location',
        'encryption',
        'mfa',
        'subcontractors',
        'incident_notification',
        'backup_continuity',
        'audits',
        'vulnerability_management',
        'reversibility',
        'contract_clauses',
    ];

    /** Seuils du niveau indicatif (score en %). */
    public const LEVEL_GOOD = 80;
    public const LEVEL_FAIR = 50;

    /**
     * Réponses retenues : questions connues et réponses valides uniquement.
     *
     * @param array<mixed> $answers question => réponse
     * @return array<string, string>
     */
    public static function sanitize(array $answers): array
    {
        $clean = [];
        foreach (self::QUESTIONS as $question) {
            $answer = (string) ($answers[$question] ?? '');
            if (isset(self::POINTS[$answer]) || $answer === self::ANSWER_NA) {
                $clean[$question] = $answer;
            }
        }

        return $clean;
    }

    /**
     * @param array<string, string> $answers
     * @return int|null score en %, null si aucune question applicable n'a reçu de réponse
     */
    public static function score(array $answers): ?int
    {
        $points = 0.0;
        $applicable = 0;
        foreach (self::sanitize($answers) as $answer) {
            if ($answer === self::ANSWER_NA) {
                continue;
            }
            $points += self::POINTS[$answer];
            $applicable++;
        }

        return $applicable === 0 ? null : (int) round(100 * $points / $applicable);
    }

    /**
     * @return string « good », « fair » ou « poor » (null : pas de score)
     */
    public static function level(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return $score >= self::LEVEL_GOOD ? 'good' : ($score >= self::LEVEL_FAIR ? 'fair' : 'poor');
    }
}
