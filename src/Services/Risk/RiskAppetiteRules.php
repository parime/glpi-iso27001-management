<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Issue #378 : appétence et tolérance au risque par catégorie, sans dépendance à GLPI (testé
 * unitairement).
 *
 * L'appétence est le niveau de risque le plus élevé que la direction accepte sans action ; la
 * tolérance, le niveau au-delà duquel le risque n'est plus supportable. Les niveaux sont ceux de la
 * matrice de risque (low, medium, high, critical). Une catégorie sans appétence définie n'est pas
 * évaluée : rien ne change tant que la direction n'a rien fixé.
 */
final class RiskAppetiteRules
{
    /** Niveaux de risque, du plus faible au plus élevé. */
    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    public const WITHIN = 'within';
    public const ABOVE_APPETITE = 'above_appetite';
    public const ABOVE_TOLERANCE = 'above_tolerance';
    public const UNDEFINED = 'undefined';

    public static function isLevel(?string $level): bool
    {
        return $level !== null && in_array($level, self::LEVELS, true);
    }

    public static function rank(?string $level): int
    {
        $rank = array_search($level, self::LEVELS, true);

        return is_int($rank) ? $rank : -1;
    }

    /**
     * Couple appétence / tolérance valide : deux niveaux connus, la tolérance au moins égale à
     * l'appétence. Une tolérance vide vaut l'appétence.
     */
    public static function isValidPair(?string $appetite, ?string $tolerance): bool
    {
        if (!self::isLevel($appetite)) {
            return false;
        }
        if ($tolerance === null || $tolerance === '') {
            return true;
        }

        return self::isLevel($tolerance) && self::rank($tolerance) >= self::rank($appetite);
    }

    /**
     * Position d'un niveau de risque par rapport à l'appétence et à la tolérance de sa catégorie.
     */
    public static function position(?string $level, ?string $appetite, ?string $tolerance): string
    {
        if (!self::isLevel($level) || !self::isValidPair($appetite, $tolerance)) {
            return self::UNDEFINED;
        }
        $tolerance = ($tolerance === null || $tolerance === '') ? $appetite : $tolerance;
        if (self::rank($level) > self::rank($tolerance)) {
            return self::ABOVE_TOLERANCE;
        }
        if (self::rank($level) > self::rank($appetite)) {
            return self::ABOVE_APPETITE;
        }

        return self::WITHIN;
    }

    /**
     * Niveau comparé à l'appétence : le risque résiduel (après traitement) quand il est évalué, sinon
     * le risque initial (ISO 27005 : c'est le risque résiduel qui est accepté ou non).
     */
    public static function retainedLevel(?string $residual, ?string $initial): string
    {
        return self::isLevel($residual) ? (string) $residual : (string) $initial;
    }

    public static function isAboveAppetite(string $position): bool
    {
        return in_array($position, [self::ABOVE_APPETITE, self::ABOVE_TOLERANCE], true);
    }

    /**
     * Un risque au-dessus de l'appétence exige une décision : un traitement (réduire, transférer,
     * éviter) ou une acceptation formelle. Sans décision, ou un risque encore ouvert sans
     * traitement, il reste « à décider ».
     */
    public static function needsDecision(string $position, string $treatment, string $status): bool
    {
        return self::isAboveAppetite($position) && $status !== 'closed' && $treatment === '';
    }

    /** Acceptation : rien à signer, signature de la personne, signature de la direction, refus. */
    public const ACCEPT_NONE = 'none';
    public const ACCEPT_SIGN = 'sign';
    public const ACCEPT_DIRECTION = 'direction';
    public const ACCEPT_REFUSED = 'refused';

    /**
     * Qui peut signer l'acceptation d'un risque ? Au-dessus de l'appétence, la personne qui l'enregistre.
     * Au-delà de la tolérance, seulement un membre du groupe Direction, quand ce groupe est défini :
     * sinon, rien ne bloque (aucun groupe ne pourrait signer). Un membre de la direction signe toujours
     * au titre de la direction.
     */
    public static function acceptance(
        string $position,
        string $treatment,
        bool $directionConfigured,
        bool $signerIsDirection
    ): string {
        if (!self::isFormalAcceptance($position, $treatment)) {
            return self::ACCEPT_NONE;
        }
        if ($signerIsDirection) {
            return self::ACCEPT_DIRECTION;
        }
        if ($position === self::ABOVE_TOLERANCE && $directionConfigured) {
            return self::ACCEPT_REFUSED;
        }

        return self::ACCEPT_SIGN;
    }

    /**
     * Une acceptation déjà signée doit-elle encore l'être par la direction ? C'est le cas d'un risque
     * réévalué au-delà de la tolérance après une signature hors direction.
     */
    public static function needsDirectionSignature(
        string $position,
        string $treatment,
        bool $directionConfigured,
        bool $signedByDirection
    ): bool {
        return $directionConfigured && $position === self::ABOVE_TOLERANCE && $treatment === 'accept'
            && !$signedByDirection;
    }

    /**
     * Accepter un risque au-dessus de l'appétence est une acceptation formelle : elle doit être
     * justifiée (la personne qui l'enregistre et la date sont conservées par l'appelant).
     */
    public static function isFormalAcceptance(string $position, string $treatment): bool
    {
        return self::isAboveAppetite($position) && $treatment === 'accept';
    }
}
