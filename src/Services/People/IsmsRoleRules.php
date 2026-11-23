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

namespace GlpiPlugin\Grcmanager\Services\People;

/**
 * Issue #1054 : règles des rôles du SMSI (RSSI et DPO), sans base de données : format de l'adresse
 * générique, reprise de l'ancien champ texte « DPO » et texte du contact affiché sur les documents.
 */
final class IsmsRoleRules
{
    public const EMAIL_MAX_LENGTH = 255;
    public const NOTE_MAX_LENGTH = 255;

    /** Séparateurs retirés autour de ce qui reste de l'ancien champ une fois l'adresse extraite. */
    private const NOTE_TRIM = " \t\n\r\0\x0B,;:-";

    /**
     * Adresse générique saisie : '' si vide, l'adresse en minuscules si le format est valable,
     * null sinon (refusée, le réglage précédent est gardé).
     */
    public static function normalizeEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return $value === null ? '' : null;
        }
        $email = mb_strtolower(trim($value));
        if ($email === '') {
            return '';
        }
        if (mb_strlen($email) > self::EMAIL_MAX_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * Reprise de l'ancien champ texte « DPO » : la première adresse e-mail trouvée devient l'adresse
     * générique, le reste du texte (nom, téléphone...) est gardé comme note « à reprendre ». Rien
     * n'est perdu : sans adresse, tout le texte devient la note.
     *
     * @return array{email: string, note: string}
     */
    public static function splitLegacyContact(string $value): array
    {
        // Adresse cherchée avant de retirer les balises : « Nom <adresse> » serait pris pour une balise.
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));
        $email = '';
        if (preg_match('/(?:mailto:)?([^\s,;:<>()\[\]"\']+@[^\s,;:<>()\[\]"\']+)/iu', $text, $match) === 1) {
            $candidate = self::normalizeEmail(rtrim($match[1], '.'));
            if ($candidate !== null && $candidate !== '') {
                $email = $candidate;
                $text = str_replace($match[0], ' ', $text);
            }
        }
        // Crochets vidés par l'extraction (« Nom <adresse> », « Nom (adresse) ») retirés.
        $text = strip_tags((string) preg_replace('/\(\s*\)|<\s*>|\[\s*\]/u', '', $text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $note = trim($text, self::NOTE_TRIM);

        return ['email' => $email, 'note' => mb_substr($note, 0, self::NOTE_MAX_LENGTH)];
    }

    /**
     * Contact du rôle sur un document (registre article 30, registre des violations) : nom du groupe
     * et de ses membres, puis l'adresse générique. Sans groupe ni adresse, l'ancienne note.
     *
     * @param list<string> $members
     */
    public static function contactText(string $groupName, array $members, string $email, string $note): string
    {
        $parts = [];
        if ($groupName !== '') {
            $parts[] = $members === [] ? $groupName : sprintf('%s (%s)', $groupName, implode(', ', $members));
        }
        if ($email !== '') {
            $parts[] = $email;
        }

        return $parts === [] ? $note : implode(', ', $parts);
    }
}
