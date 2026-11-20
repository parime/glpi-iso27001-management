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

namespace GlpiPlugin\Grcmanager\Services\Evidence;

/**
 * Issue #868 : règles pures (sans GLPI) du dossier de preuves exportable pour l'auditeur : quelles
 * pièces entrent dans l'archive, sous quel nom, et dans quelle limite de taille.
 */
final class EvidencePackRules
{
    /** Limite de taille de l'archive, en mégaoctets (réglable). */
    public const DEFAULT_MAX_MB = 200;
    public const MIN_MAX_MB = 1;
    public const MAX_MAX_MB = 2048;

    /** Pièce incluse dans l'archive. */
    public const STATE_INCLUDED = 'included';
    /** Date de validité dépassée : signalée dans l'index, pas incluse. */
    public const STATE_EXPIRED = 'expired';
    /** Document sans fichier, ou fichier introuvable sur le disque. */
    public const STATE_NO_FILE = 'no_file';
    /** Le fichier ferait dépasser la limite de taille de l'archive. */
    public const STATE_TOO_LARGE = 'too_large';

    /** Dossier des pièces dans l'archive (chaque pièce n'y figure qu'une fois). */
    public const FILES_FOLDER = 'evidence';

    public static function normalizeMaxMb(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_MB;
        }

        return max(self::MIN_MAX_MB, min(self::MAX_MAX_MB, (int) $value));
    }

    public static function maxBytes(int $maxMb): int
    {
        return self::normalizeMaxMb($maxMb) * 1024 * 1024;
    }

    /**
     * État d'une pièce : une preuve expirée n'est jamais incluse, même si elle tient dans la limite.
     *
     * @param string $validityStatus statut EvidenceValidity (none, valid, expiring, expired)
     */
    public static function state(string $validityStatus, bool $hasFile, int $size, int $usedBytes, int $maxBytes): string
    {
        if ($validityStatus === EvidenceValidity::STATUS_EXPIRED) {
            return self::STATE_EXPIRED;
        }
        if (!$hasFile) {
            return self::STATE_NO_FILE;
        }
        if ($usedBytes + max(0, $size) > $maxBytes) {
            return self::STATE_TOO_LARGE;
        }

        return self::STATE_INCLUDED;
    }

    /**
     * Nom de la pièce dans l'archive : identifiant du document (unique) puis nom de fichier réduit
     * aux caractères sûrs, sans chemin ni « .. ».
     */
    public static function entryName(int $documentsId, string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $safe = trim((string) preg_replace('/\.{2,}/', '.', $safe), '._');
        if ($safe === '') {
            $safe = 'document';
        }
        if (strlen($safe) > 100) {
            $extension = pathinfo($safe, PATHINFO_EXTENSION);
            $suffix = $extension !== '' && strlen($extension) <= 10 ? '.' . $extension : '';
            $safe = substr($safe, 0, 100 - strlen($suffix)) . $suffix;
        }

        return self::FILES_FOLDER . '/' . $documentsId . '-' . $safe;
    }

    /**
     * Nom du fichier de l'archive : entité et date de génération.
     */
    public static function fileName(int $entityId, string $now): string
    {
        return sprintf('evidence-pack-entity%d-%s.zip', $entityId, date('Ymd-His', (int) strtotime($now)));
    }

    /**
     * Contenu du fichier d'empreintes (format de sha256sum : empreinte, deux espaces, chemin).
     *
     * @param array<string, string> $hashes chemin dans l'archive => empreinte SHA-256
     */
    public static function checksums(array $hashes): string
    {
        $lines = '';
        foreach ($hashes as $entry => $hash) {
            $lines .= $hash . '  ' . $entry . "\n";
        }

        return $lines;
    }

    /**
     * Tri naturel des codes de mesure ou de sous-article (A.5.2 avant A.5.10, 4.1 avant 10.1).
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public static function sortCodes(array $codes): array
    {
        usort($codes, 'strnatcmp');

        return $codes;
    }

    /**
     * Synthèse des pièces : nombre par état et taille incluse.
     *
     * @param list<array{state: string, size: int}> $files
     * @return array{included: int, expired: int, no_file: int, too_large: int, bytes: int}
     */
    public static function summary(array $files): array
    {
        $summary = [
            self::STATE_INCLUDED  => 0,
            self::STATE_EXPIRED   => 0,
            self::STATE_NO_FILE   => 0,
            self::STATE_TOO_LARGE => 0,
            'bytes'               => 0,
        ];
        foreach ($files as $file) {
            if (isset($summary[$file['state']])) {
                $summary[$file['state']]++;
            }
            if ($file['state'] === self::STATE_INCLUDED) {
                $summary['bytes'] += max(0, $file['size']);
            }
        }

        return $summary;
    }
}
