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

namespace GlpiPlugin\Grcmanager\Services\MailDomain;

/**
 * Issue #855 : question DNS minimale au format du protocole (RFC 1035), pour un type que la
 * fonction dns_get_record de PHP ne sait pas demander (DS, preuve d'une délégation signée par
 * DNSSEC). Fonctions pures : construction de la question et lecture de la réponse, sans réseau.
 */
final class DnsWire
{
    public const TYPE_DS = 43;

    /** Taille maximale d'une réponse lue (octets). */
    public const MAX_BYTES = 4096;

    /**
     * Question récursive (bit RD) d'un seul nom, classe IN.
     */
    public static function query(int $id, string $name, int $type): string
    {
        $packet = pack('nnnnnn', $id & 0xFFFF, 0x0100, 1, 0, 0, 0);
        foreach (explode('.', trim($name, '.')) as $label) {
            $packet .= chr(strlen($label)) . $label;
        }

        return $packet . "\0" . pack('nn', $type & 0xFFFF, 1);
    }

    /**
     * Code de réponse et nombre de réponses du type demandé, ou null si la réponse est illisible
     * ou ne correspond pas à la question (identifiant différent, pas une réponse, paquet tronqué).
     *
     * @return array{rcode: int, count: int}|null
     */
    public static function answers(string $packet, int $id, int $type): ?array
    {
        if (strlen($packet) < 12) {
            return null;
        }
        $header = unpack('nid/nflags/nqd/nan', substr($packet, 0, 8));
        if (!is_array($header) || (int) $header['id'] !== ($id & 0xFFFF) || ((int) $header['flags'] & 0x8000) === 0) {
            return null;
        }
        $offset = 12;
        for ($i = 0; $i < (int) $header['qd']; $i++) {
            $offset = self::skipName($packet, $offset);
            if ($offset === null) {
                return null;
            }
            $offset += 4;
        }
        $count = 0;
        for ($i = 0; $i < (int) $header['an']; $i++) {
            $offset = self::skipName($packet, $offset);
            if ($offset === null || $offset + 10 > strlen($packet)) {
                return null;
            }
            $fields = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            if (!is_array($fields)) {
                return null;
            }
            if ((int) $fields['type'] === $type) {
                $count++;
            }
            $offset += 10 + (int) $fields['length'];
            if ($offset > strlen($packet)) {
                return null;
            }
        }

        return ['rcode' => (int) $header['flags'] & 0x0F, 'count' => $count];
    }

    /**
     * Position qui suit un nom (étiquettes ou pointeur de compression), null si le nom déborde.
     */
    private static function skipName(string $packet, int $offset): ?int
    {
        $length = strlen($packet);
        for ($guard = 0; $guard < 128; $guard++) {
            if ($offset >= $length) {
                return null;
            }
            $byte = ord($packet[$offset]);
            if ($byte === 0) {
                return $offset + 1;
            }
            if (($byte & 0xC0) === 0xC0) {
                return $offset + 2 <= $length ? $offset + 2 : null;
            }
            $offset += 1 + $byte;
        }

        return null;
    }
}
