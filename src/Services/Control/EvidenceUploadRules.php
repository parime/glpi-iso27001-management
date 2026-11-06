<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Issue #380 : preuve envoyée directement depuis le formulaire d'un test d'efficacité. Règles sans
 * dépendance à GLPI (testées unitairement) : nom de fichier sûr, erreur d'envoi, et concordance
 * entre le type réel du contenu (détecté par finfo) et l'extension.
 *
 * Les types et la taille maximale restent ceux de GLPI (types de documents « téléversables »,
 * réglage « Taille maximale des documents ») : voir ControlTest::storeEvidenceFile().
 */
final class EvidenceUploadRules
{
    /** Longueur maximale du nom conservé (glpi_documents.filename est un varchar(255)). */
    public const MAX_NAME_LENGTH = 180;

    public const ERROR_TOO_LARGE = 'too_large';
    public const ERROR_EMPTY = 'empty';
    public const ERROR_FAILED = 'failed';

    /**
     * Contenus actifs jamais acceptés comme preuve, quelle que soit l'extension annoncée et même si
     * un administrateur a rendu le type téléversable dans GLPI : page web, image vectorielle,
     * script, exécutable.
     */
    public const FORBIDDEN_MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php',
        'text/x-shellscript', 'application/x-sh', 'text/x-python', 'text/x-perl',
        'application/x-executable', 'application/x-dosexec', 'application/x-msdownload',
        'application/x-sharedlib', 'application/x-mach-binary', 'application/java-archive',
    ];

    /**
     * Types réels admis pour une extension, en plus du type que GLPI associe à l'extension
     * (glpi_documenttypes.mime) : finfo ne rend pas toujours ce type exact (CSV vu comme du texte,
     * documents bureautiques vus comme des archives ZIP ou OLE).
     *
     * @var array<string, list<string>>
     */
    public const EQUIVALENT_MIMES = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'txt'  => ['text/plain'],
        'log'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'json' => ['application/json', 'text/plain'],
        'eml'  => ['message/rfc822', 'text/plain'],
        'msg'  => ['application/vnd.ms-outlook', 'application/CDFV2', 'application/x-ole-storage'],
        'zip'  => ['application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage'],
    ];

    /**
     * Nom de fichier sûr tiré du nom annoncé par le navigateur : dernier segment seulement (aucun
     * chemin), sans caractère de contrôle ni caractère interdit dans un nom de fichier, avec une
     * extension alphanumérique. Null si rien d'utilisable ne reste.
     */
    public static function safeName(mixed $clientName): ?string
    {
        if (!is_string($clientName)) {
            return null;
        }
        $parts = preg_split('#[/\\\\]#', $clientName) ?: [];
        $name = (string) end($parts);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name), ' .');
        if (preg_match('/^(.+)\.([A-Za-z0-9]{1,10})$/u', $name, $matches) !== 1) {
            return null;
        }
        $base = trim($matches[1], ' .');
        if ($base === '') {
            return null;
        }
        $extension = $matches[2];
        $base = mb_substr($base, 0, self::MAX_NAME_LENGTH - strlen($extension) - 1);

        return $base . '.' . $extension;
    }

    public static function extension(string $safeName): string
    {
        return strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
    }

    /**
     * Erreur d'envoi (PHP) ou taille hors limite : code d'erreur, null si l'envoi est utilisable.
     */
    public static function uploadError(int $phpError, int $size, int $maxBytes): ?string
    {
        if (in_array($phpError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return self::ERROR_TOO_LARGE;
        }
        if ($phpError === UPLOAD_ERR_NO_FILE) {
            return self::ERROR_EMPTY;
        }
        if ($phpError !== UPLOAD_ERR_OK) {
            return self::ERROR_FAILED;
        }
        if ($size <= 0) {
            return self::ERROR_EMPTY;
        }

        return $maxBytes > 0 && $size > $maxBytes ? self::ERROR_TOO_LARGE : null;
    }

    /**
     * Le contenu est-il bien du type que son extension annonce ? Le type réel ($detected, finfo)
     * doit être celui que GLPI associe à l'extension ($glpiMime) ou un type équivalent connu, et
     * jamais un contenu actif.
     */
    public static function realTypeMatches(string $extension, string $detected, ?string $glpiMime): bool
    {
        $detected = strtolower(trim($detected));
        if ($detected === '' || in_array($detected, self::FORBIDDEN_MIMES, true)) {
            return false;
        }
        $glpiMime = strtolower(trim((string) $glpiMime));
        if ($glpiMime !== '' && $detected === $glpiMime) {
            return true;
        }

        return in_array(
            $detected,
            array_map('strtolower', self::EQUIVALENT_MIMES[strtolower($extension)] ?? []),
            true
        );
    }
}
