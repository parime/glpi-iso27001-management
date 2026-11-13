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

namespace GlpiPlugin\Grcmanager\Services\Notification;

/**
 * Issue #582 : lecture des catalogues de traduction du plugin (locales/<langue>.mo) pour une langue
 * donnée, sans changer la langue de la session. Sert à écrire les traductions des modèles de
 * notification (une par langue) à l'installation : GLPI ne charge que la langue de la session.
 */
final class MoCatalog
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * Traducteur d'une langue : la traduction du catalogue, sinon le msgid (anglais). Langue vide :
     * le msgid tel quel (traduction par défaut des modèles, en anglais).
     *
     * @return \Closure(string): string
     */
    public static function translator(string $language): \Closure
    {
        $messages = $language === '' ? [] : self::messages($language);

        return static fn (string $msgid): string => ($messages[$msgid] ?? '') !== '' ? $messages[$msgid] : $msgid;
    }

    /** @return array<string, string> msgid => traduction (formes simples seulement) */
    public static function messages(string $language): array
    {
        if (isset(self::$cache[$language])) {
            return self::$cache[$language];
        }
        $messages = [];
        if (preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,4})?$/', $language) === 1) {
            $file = dirname(__DIR__, 3) . '/locales/' . $language . '.mo';
            $data = is_readable($file) ? file_get_contents($file) : false;
            if (is_string($data)) {
                $messages = self::parse($data);
            }
        }

        return self::$cache[$language] = $messages;
    }

    /**
     * Format GNU gettext .mo : en-tête (nombre de chaînes, positions des tables), puis pour chaque
     * chaîne sa longueur et sa position, originaux et traductions dans le même ordre.
     *
     * @return array<string, string>
     */
    public static function parse(string $data): array
    {
        if (strlen($data) < 28) {
            return [];
        }
        $magic = self::word($data, 0, 'V');
        $format = match ($magic) {
            0x950412de => 'V',
            0xde120495 => 'N',
            default    => null,
        };
        if ($format === null) {
            return [];
        }
        $count = self::word($data, 8, $format);
        $originals = self::word($data, 12, $format);
        $translations = self::word($data, 16, $format);
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $msgid = substr($data, self::word($data, $originals + 8 * $i + 4, $format), self::word($data, $originals + 8 * $i, $format));
            $msgstr = substr($data, self::word($data, $translations + 8 * $i + 4, $format), self::word($data, $translations + 8 * $i, $format));
            if ($msgid === '' || $msgstr === '') {
                continue;
            }
            if (str_contains($msgid, "\0")) {
                // Entrée au pluriel : le singulier sert aussi de msgid simple (première forme).
                $msgid = explode("\0", $msgid)[0];
                $msgstr = explode("\0", $msgstr)[0];
                if (isset($messages[$msgid]) || $msgstr === '') {
                    continue;
                }
            }
            $messages[$msgid] = $msgstr;
        }

        return $messages;
    }

    private static function word(string $data, int $offset, string $format): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            return 0;
        }
        $value = unpack($format, $data, $offset);

        return is_array($value) ? (int) $value[1] : 0;
    }
}
