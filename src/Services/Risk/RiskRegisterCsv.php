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

namespace GlpiPlugin\Grcmanager\Services\Risk;

use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;

/**
 * Issue #602 : lecture du fichier CSV d'un registre des risques tenu dans un tableur, sans dépendance
 * à GLPI (testée unitairement). Même lecteur que l'import de référentiel personnalisé (#537) : BOM,
 * séparateur « ; », « , » ou tabulation, champs entre guillemets sur plusieurs lignes, en-têtes
 * français ou anglais ; Windows-1252 converti par toUtf8().
 *
 * Les valeurs sont rapprochées avec tolérance : clé interne, libellé français ou anglais, libellé de
 * la langue de l'utilisateur (fourni par l'écran), ou nombre sur une échelle de 1 à N ramené aux
 * quatre niveaux de la matrice. Les propriétaires, actifs et mesures sont rapprochés grâce aux
 * tables de correspondance fournies par l'appelant ; rien n'est jamais créé. Une ligne invalide est
 * rejetée avec son numéro et sa raison, jamais ignorée en silence.
 *
 * Une cellule vide ne dit rien : la valeur enregistrée reste (voir RiskRegisterSync).
 */
final class RiskRegisterCsv
{
    public const MAX_ROWS = 2000;
    public const MAX_REFERENCE_LENGTH = 100;
    public const SCALES = [3, 4, 5, 10];

    /** Préfixe de la référence exportée d'un risque saisi dans GLPI sans référence externe. */
    public const INTERNAL_PREFIX = 'GLPI-RISK-';

    // Raisons de rejet (traduites par l'écran d'import).
    public const ERROR_EMPTY = 'empty';
    public const ERROR_MISSING_COLUMNS = 'missing_columns';
    public const ERROR_MISSING_REFERENCE = 'missing_reference';
    public const ERROR_REFERENCE_TOO_LONG = 'reference_too_long';
    public const ERROR_MISSING_TITLE = 'missing_title';
    public const ERROR_DUPLICATE_REFERENCE = 'duplicate_reference';
    public const ERROR_UNKNOWN_VALUE = 'unknown_value';
    public const ERROR_UNKNOWN_OWNER = 'unknown_owner';
    public const ERROR_AMBIGUOUS_OWNER = 'ambiguous_owner';
    public const ERROR_UNKNOWN_ANNEX_A = 'unknown_annex_a';
    public const ERROR_UNKNOWN_ASSET = 'unknown_asset';
    public const ERROR_AMBIGUOUS_ASSET = 'ambiguous_asset';
    public const ERROR_INVALID_DATE = 'invalid_date';
    public const ERROR_TOO_MANY_ROWS = 'too_many_rows';

    /** Colonnes du modèle et de l'export, dans l'ordre. */
    public const COLUMNS = [
        'reference', 'title', 'description', 'category', 'probability', 'impact', 'residual_probability',
        'residual_impact', 'treatment', 'justification', 'owner', 'review_date', 'status', 'annex_a', 'assets',
    ];

    /** Colonnes calculées : écrites par l'export, ignorées à l'import. */
    public const COMPUTED_COLUMNS = ['risk_level', 'residual_level'];

    /** Colonnes à valeur fermée (liste de valeurs du risque). */
    public const ENUM_COLUMNS = [
        'category', 'probability', 'impact', 'residual_probability', 'residual_impact', 'treatment', 'status',
    ];

    private const HEADERS = [
        'reference'            => ['reference', 'ref', 'external_reference', 'external_ref', 'reference_externe',
            'id', 'identifiant', 'identifier', 'code', 'risk_id', 'id_risque', 'numero', 'n', 'no', 'num'],
        'title'                => ['title', 'titre', 'risk', 'risque', 'name', 'nom', 'intitule', 'libelle',
            'risk_title', 'titre_du_risque'],
        'description'          => ['description', 'detail', 'details', 'scenario', 'scenario_de_risque',
            'risk_scenario'],
        'category'             => ['category', 'categorie', 'type', 'risk_category', 'categorie_de_risque',
            'famille'],
        'residual_probability' => ['residual_probability', 'probabilite_residuelle', 'residual_likelihood',
            'vraisemblance_residuelle'],
        'residual_impact'      => ['residual_impact', 'impact_residuel', 'gravite_residuelle', 'residual_severity'],
        'probability'          => ['probability', 'probabilite', 'likelihood', 'vraisemblance', 'occurrence',
            'frequence', 'frequency', 'initial_probability', 'probabilite_initiale'],
        'impact'               => ['impact', 'gravite', 'severity', 'consequence', 'consequences',
            'initial_impact', 'impact_initial'],
        'treatment'            => ['treatment', 'traitement', 'decision', 'decision_de_traitement',
            'treatment_decision', 'option_de_traitement', 'treatment_option', 'strategie', 'strategy'],
        'justification'        => ['justification', 'rationale', 'commentaire', 'comment', 'comments'],
        'owner'                => ['owner', 'proprietaire', 'responsable', 'risk_owner', 'proprietaire_du_risque',
            'pilote'],
        'review_date'          => ['review_date', 'date_de_revue', 'revue', 'next_review', 'prochaine_revue',
            'date_revue', 'review'],
        'status'               => ['status', 'statut', 'etat', 'state'],
        'annex_a'              => ['annex_a', 'annexe_a', 'mesures', 'mesures_annexe_a', 'controls', 'mesures_iso',
            'iso_27001', 'iso27001', 'controles', 'mesures_de_securite'],
        'assets'               => ['assets', 'actifs', 'actifs_glpi', 'glpi_assets', 'items', 'equipements',
            'actifs_concernes', 'affected_assets'],
        'risk_level'           => ['risk_level', 'niveau_de_risque', 'niveau', 'level', 'niveau_initial'],
        'residual_level'       => ['residual_level', 'niveau_residuel', 'niveau_de_risque_residuel',
            'residual_risk_level'],
    ];

    /** Échelle commune à 4 niveaux, des deux axes, souvent employée dans les tableurs. */
    private const GENERIC_LEVELS = [
        1 => ['low', 'faible', 'bas', 'basse', 'very_low', 'tres_faible', 'minimal', 'minime', 'minor', 'mineur',
            'negligible', 'negligeable', 'limite', 'limited'],
        2 => ['medium', 'moyen', 'moyenne', 'modere', 'moderee', 'moderate', 'significatif', 'significant'],
        3 => ['high', 'eleve', 'elevee', 'fort', 'forte', 'major', 'majeur', 'majeure', 'important', 'importante',
            'grave'],
        4 => ['very_high', 'tres_eleve', 'tres_elevee', 'tres_fort', 'tres_forte', 'critical', 'critique', 'severe',
            'catastrophic', 'catastrophique', 'vital', 'maximal', 'maximum'],
    ];

    private const PROBABILITY_KEYS = [1 => 'rare', 2 => 'possible', 3 => 'probable', 4 => 'certain'];
    private const IMPACT_KEYS = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];

    private const SYNONYMS = [
        'probability' => [
            'rare'     => ['rare', 'unlikely', 'improbable', 'peu_probable', 'tres_peu_probable', 'very_unlikely'],
            'possible' => ['possible', 'occasional', 'occasionnel', 'occasionnelle', 'plausible'],
            'probable' => ['probable', 'likely', 'vraisemblable', 'frequent', 'frequente'],
            'certain'  => ['certain', 'almost_certain', 'quasi_certain', 'presque_certain', 'tres_probable',
                'very_likely', 'quasi_certaine'],
        ],
        'impact' => [
            'low'      => [],
            'medium'   => [],
            'high'     => [],
            'critical' => [],
        ],
        'category' => [
            'people'      => ['people', 'humain', 'human', 'personnes', 'personnel', 'rh', 'hr', 'ressources_humaines'],
            'process'     => ['process', 'processus', 'organisationnel', 'organisationnelle', 'organizational',
                'organisation', 'organization', 'gouvernance', 'governance'],
            'physical'    => ['physical', 'physique', 'environnement', 'environmental', 'environnemental',
                'locaux', 'site'],
            'third_party' => ['third_party', 'tiers', 'fournisseur', 'fournisseurs', 'supplier', 'suppliers',
                'prestataire', 'vendor', 'third_party_supplier', 'tiers_fournisseur', 'sous_traitant'],
            'technical'   => ['technical', 'technique', 'technologique', 'technological', 'it', 'informatique',
                'cyber', 'si'],
        ],
        'treatment' => [
            ''         => ['none', 'aucune', 'aucun', 'no_decision', 'aucune_decision', 'a_definir', 'to_be_defined',
                'tbd', 'undecided'],
            'accept'   => ['accept', 'accepter', 'acceptation', 'accepte', 'accepted', 'retain', 'retention',
                'conserver', 'maintenir', 'tolerate', 'tolerer', 'prise_de_risque'],
            'mitigate' => ['mitigate', 'mitigation', 'reduce', 'reduire', 'reduction', 'attenuer', 'attenuation',
                'traiter', 'treat', 'modify', 'modifier', 'modification', 'reduce_the_risk'],
            'transfer' => ['transfer', 'transferer', 'transfert', 'share', 'partager', 'partage', 'assurance',
                'insurance', 'externaliser'],
            'avoid'    => ['avoid', 'eviter', 'evitement', 'refuser', 'refus', 'supprimer', 'suppression',
                'terminate', 'eliminate', 'eliminer'],
        ],
        'status' => [
            'identified'   => ['identified', 'identifie', 'identifiee', 'nouveau', 'new', 'open', 'ouvert',
                'a_traiter', 'to_do', 'todo'],
            'in_treatment' => ['in_treatment', 'en_traitement', 'en_cours', 'in_progress', 'ongoing',
                'en_cours_de_traitement'],
            'accepted'     => ['accepted', 'accepte', 'acceptee'],
            'closed'       => ['closed', 'clos', 'close', 'ferme', 'fermee', 'cloture', 'cloturee', 'termine',
                'terminee', 'done'],
        ],
    ];

    /**
     * @param array{labels?: array<string, array<string, string>>, users?: array<string, list<int>>,
     *     controls?: list<string>, assets?: array<string, list<string>>} $lookups
     *     labels : colonne => libellé affiché => clé (langue de l'utilisateur) ; users : identifiant,
     *     adresse e-mail ou nom en minuscules => utilisateurs ; controls : codes de l'Annexe A ;
     *     assets : nom ou numéro d'inventaire en minuscules => « Itemtype:id »
     * @param int $scale valeur maximale des notes chiffrées du fichier (4 : de 1 à 4)
     * @return array{columns: list<string>, rows: list<array{line: int, reference: string, title: string,
     *     values: array<string, mixed>}>,
     *     errors: list<array{line: int, reference: string, reason: string, detail: string}>}
     */
    public static function parse(string $content, array $lookups = [], int $scale = 4): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $scale = in_array($scale, self::SCALES, true) ? $scale : 4;
        $records = CustomFrameworkCsv::records($content);
        if ($records === []) {
            return ['columns' => [], 'rows' => [], 'errors' => [self::error(1, '', self::ERROR_EMPTY)]];
        }
        [$headerLine, $header] = $records[0];
        $columns = self::mapHeader($header);
        if (!in_array('reference', $columns, true) || !in_array('title', $columns, true)) {
            return [
                'columns' => [],
                'rows'    => [],
                'errors'  => [self::error($headerLine, '', self::ERROR_MISSING_COLUMNS)],
            ];
        }
        $present = array_values(array_filter(
            array_unique($columns),
            static fn (string $column): bool => $column !== '' && !in_array($column, self::COMPUTED_COLUMNS, true)
        ));
        $rows = [];
        $errors = [];
        $seen = [];
        foreach (array_slice($records, 1) as [$line, $fields]) {
            $cells = [];
            foreach ($fields as $position => $value) {
                $column = $columns[$position] ?? '';
                if ($column !== '' && !in_array($column, self::COMPUTED_COLUMNS, true)) {
                    $cells[$column] = self::restoreFormula(trim((string) $value));
                }
            }
            if (implode('', $cells) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = self::error($line, '', self::ERROR_TOO_MANY_ROWS, (string) self::MAX_ROWS);
                break;
            }
            $reference = self::normalizeSpaces($cells['reference'] ?? '');
            if ($reference === '') {
                $errors[] = self::error($line, '', self::ERROR_MISSING_REFERENCE);
                continue;
            }
            if (mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
                $errors[] = self::error(
                    $line,
                    mb_substr($reference, 0, self::MAX_REFERENCE_LENGTH),
                    self::ERROR_REFERENCE_TOO_LONG
                );
                continue;
            }
            $title = self::normalizeSpaces($cells['title'] ?? '');
            if ($title === '') {
                $errors[] = self::error($line, $reference, self::ERROR_MISSING_TITLE);
                continue;
            }
            $key = mb_strtolower($reference);
            if (isset($seen[$key])) {
                $errors[] = self::error($line, $reference, self::ERROR_DUPLICATE_REFERENCE, (string) $seen[$key]);
                continue;
            }
            $values = ['title' => mb_substr($title, 0, 255)];
            $error = self::readValues($cells, $lookups, $scale, $values);
            if ($error !== null) {
                $errors[] = self::error($line, $reference, $error[0], $error[1]);
                continue;
            }
            $seen[$key] = $line;
            $rows[] = ['line' => $line, 'reference' => $reference, 'title' => $values['title'], 'values' => $values];
        }

        return ['columns' => $present, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * Valeurs non vides d'une ligne, rapprochées. Rend la première erreur rencontrée.
     *
     * @param array<string, string> $cells
     * @param array<string, mixed>  $lookups
     * @param array<string, mixed>  $values
     * @return array{0: string, 1: string}|null
     */
    private static function readValues(array $cells, array $lookups, int $scale, array &$values): ?array
    {
        foreach (['description', 'justification'] as $column) {
            if (($cells[$column] ?? '') !== '') {
                $values[$column] = $cells[$column];
            }
        }
        foreach (self::ENUM_COLUMNS as $column) {
            $cell = $cells[$column] ?? '';
            if ($cell === '') {
                continue;
            }
            $value = self::mapValue($column, $cell, $scale, $lookups['labels'][$column] ?? []);
            if ($value === null) {
                return [self::ERROR_UNKNOWN_VALUE, $column . "\x1F" . $cell];
            }
            $values[$column] = $value;
        }
        $owner = self::normalizeSpaces($cells['owner'] ?? '');
        if ($owner !== '') {
            $candidates = array_values(array_unique($lookups['users'][mb_strtolower($owner)] ?? []));
            if ($candidates === []) {
                return [self::ERROR_UNKNOWN_OWNER, $owner];
            }
            if (count($candidates) > 1) {
                return [self::ERROR_AMBIGUOUS_OWNER, $owner];
            }
            $values['users_id'] = (int) $candidates[0];
        }
        $date = $cells['review_date'] ?? '';
        if ($date !== '') {
            $parsed = self::parseDate($date);
            if ($parsed === null) {
                return [self::ERROR_INVALID_DATE, $date];
            }
            $values['review_date'] = $parsed;
        }
        if (($cells['annex_a'] ?? '') !== '') {
            $known = array_fill_keys($lookups['controls'] ?? [], true);
            $codes = CustomFrameworkCsv::splitAnnexA($cells['annex_a']);
            $unknown = array_values(array_filter($codes, static fn (string $code): bool => !isset($known[$code])));
            if ($unknown !== []) {
                return [self::ERROR_UNKNOWN_ANNEX_A, implode(', ', $unknown)];
            }
            sort($codes);
            $values['annex_a'] = $codes;
        }
        if (($cells['assets'] ?? '') !== '') {
            $assets = [];
            foreach (self::splitList($cells['assets']) as $name) {
                $candidates = array_values(array_unique($lookups['assets'][mb_strtolower($name)] ?? []));
                if ($candidates === []) {
                    return [self::ERROR_UNKNOWN_ASSET, $name];
                }
                if (count($candidates) > 1) {
                    return [self::ERROR_AMBIGUOUS_ASSET, $name];
                }
                $assets[$candidates[0]] = $candidates[0];
            }
            $assets = array_values($assets);
            sort($assets);
            $values['assets'] = $assets;
        }

        return null;
    }

    /**
     * Clé interne d'une valeur de liste (catégorie, probabilité, impact, traitement, statut), ou null.
     *
     * @param array<string, string> $labels libellé affiché => clé (langue de l'utilisateur)
     */
    public static function mapValue(string $column, string $cell, int $scale = 4, array $labels = []): ?string
    {
        $axis = match ($column) {
            'probability', 'residual_probability' => 'probability',
            'impact', 'residual_impact'           => 'impact',
            default                               => $column,
        };
        $slug = CustomFrameworkCsv::slug($cell);
        foreach ($labels as $label => $key) {
            if (CustomFrameworkCsv::slug((string) $label) === $slug) {
                return (string) $key;
            }
        }
        foreach (self::SYNONYMS[$axis] ?? [] as $key => $synonyms) {
            if ($slug === (string) $key || in_array($slug, $synonyms, true)) {
                return (string) $key;
            }
        }
        if ($axis !== 'probability' && $axis !== 'impact') {
            return null;
        }
        $keys = $axis === 'probability' ? self::PROBABILITY_KEYS : self::IMPACT_KEYS;
        // Note chiffrée (« 3 », « 3 - Élevé », « 3/4 ») sur l'échelle choisie, ramenée à 4 niveaux.
        if (preg_match('/^(\d{1,2})(?:[.,]0+)?(?:\D.*)?$/u', trim($cell), $match) === 1) {
            $number = (int) $match[1];
            if ($number < 1 || $number > $scale) {
                return null;
            }

            return $keys[self::scaleToLevel($number, $scale)];
        }
        foreach (self::GENERIC_LEVELS as $level => $synonyms) {
            if (in_array($slug, $synonyms, true)) {
                return $keys[$level];
            }
        }

        return null;
    }

    /**
     * Note de 1 à $scale ramenée aux 4 niveaux de la matrice : les extrêmes se correspondent, une note
     * à mi-chemin entre deux niveaux va au plus bas (de 1 à 5 : 1, 2, 2, 3, 4 ; de 1 à 3 : 1, 2, 4).
     */
    public static function scaleToLevel(int $value, int $scale): int
    {
        if ($scale === 4 || $scale < 2) {
            return max(1, min(4, $value));
        }

        return max(1, min(4, 1 + (int) ceil(($value - 1) * 3 / ($scale - 1) - 0.5)));
    }

    /**
     * Date au format AAAA-MM-JJ, JJ/MM/AAAA, JJ-MM-AAAA ou JJ.MM.AAAA (heure éventuelle ignorée).
     */
    public static function parseDate(string $cell): ?string
    {
        $cell = trim($cell);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $cell, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})(?:\s.*)?$#', $cell, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Liste d'une cellule (actifs) : virgule, point-virgule, barre verticale ou retour à la ligne.
     *
     * @return list<string>
     */
    public static function splitList(string $cell): array
    {
        $items = [];
        foreach (preg_split('/[,;|\n]+/u', $cell) ?: [] as $part) {
            $part = self::normalizeSpaces($part);
            if ($part !== '') {
                $items[mb_strtolower($part)] = $part;
            }
        }

        return array_values($items);
    }

    /**
     * Contenu converti en UTF-8 : un tableur en français enregistre souvent en Windows-1252.
     */
    public static function toUtf8(string $content): string
    {
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * Fichier CSV : en-tête puis lignes, séparateur « ; », BOM UTF-8 pour qu'un tableur l'ouvre avec
     * les accents. Toute cellule qui commence par = + - @ (ou tabulation, retour) est préfixée d'une
     * apostrophe : un tableur ne l'exécute pas comme une formule.
     *
     * @param list<string>       $header
     * @param list<list<string>> $rows
     */
    public static function build(array $header, array $rows): string
    {
        $lines = [self::csvLine($header)];
        foreach ($rows as $row) {
            $lines[] = self::csvLine(array_map([self::class, 'neutralizeFormula'], $row));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    public static function neutralizeFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * L'apostrophe ajoutée par neutralizeFormula() est retirée à la relecture (aller-retour exact).
     */
    public static function restoreFormula(string $value): string
    {
        return preg_match("/^'[=+\\-@\t\r]/", $value) === 1 ? substr($value, 1) : $value;
    }

    /**
     * @param list<string> $values
     */
    private static function csvLine(array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $cells[] = preg_match('/[;"\r\n]/', $value) === 1
                ? '"' . str_replace('"', '""', $value) . '"'
                : $value;
        }

        return implode(';', $cells);
    }

    /**
     * @param list<string> $header
     * @return array<int, string> position => colonne reconnue ('' si inconnue)
     */
    private static function mapHeader(array $header): array
    {
        $columns = [];
        $used = [];
        foreach ($header as $position => $label) {
            $slug = CustomFrameworkCsv::slug($label);
            $columns[$position] = '';
            foreach (self::HEADERS as $key => $aliases) {
                if (!isset($used[$key]) && in_array($slug, $aliases, true)) {
                    $columns[$position] = $key;
                    $used[$key] = true;
                    break;
                }
            }
        }

        return $columns;
    }

    private static function normalizeSpaces(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array{line: int, reference: string, reason: string, detail: string}
     */
    private static function error(int $line, string $reference, string $reason, string $detail = ''): array
    {
        return ['line' => $line, 'reference' => $reference, 'reason' => $reason, 'detail' => $detail];
    }
}
