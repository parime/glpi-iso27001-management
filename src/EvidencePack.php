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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Config;
use Document;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidencePackRules as Rules;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceRecurrenceRules as RecurrenceRules;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity;
use Html;
use Session;

/**
 * Issue #868 : dossier de preuves exportable pour l'auditeur (clauses 7.5.3 et 9.2). Une archive
 * ZIP par périmètre (entité) avec un index PDF qui liste, mesure par mesure et sous-article par
 * sous-article, les preuves rattachées et les rapports déjà archivés, avec leur date, leur état et
 * leur empreinte SHA-256.
 *
 * - Lecture seule : rien n'est modifié dans les registres, seule l'archive est enregistrée.
 * - Seules les pièces que la personne qui génère peut lire entrent dans l'archive (droits de GLPI
 *   et registre des documents restreints) ; les autres n'apparaissent nulle part, pas même dans
 *   l'index. Une preuve dont la date de validité est dépassée est signalée dans l'index, sans être
 *   incluse.
 * - L'archive se construit en flux (ZipArchive sur un fichier temporaire, chaque pièce lue depuis
 *   le disque à la fermeture), dans une limite de taille réglable : les pièces qui la feraient
 *   dépasser sont signalées dans l'index.
 * - L'archive est archivée comme document restreint (RestrictedDocument) : lisible par son auteur,
 *   et par qui peut lire chacune des pièces qu'elle contient, dans l'entité du périmètre.
 */
class EvidencePack extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const TABLE = 'glpi_plugin_grcmanager_evidencepacks';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';

    public const CONFIG_MAX_MB = 'evidencepack_max_mb';

    /** Fermer et rouvrir l'archive toutes les N pièces : borne le nombre de fichiers ouverts. */
    private const REOPEN_EVERY = 200;

    /**
     * Rapports archivés par le plugin (registre des documents restreints) : source => sous-article.
     */
    private const ARCHIVED_REPORTS = [
        SoaVersion::class           => '6.1.3',
        PolicyVersion::class        => '5.2',
        ResponsibilityExport::class => '5.3',
        CompetenceExport::class     => '7.2',
        Audit::class                => '9.2',
        ManagementReview::class     => '9.3',
        AccessReview::class         => 'A.5.18',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Evidence pack', 'Evidence packs', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-zip';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/evidencepack.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function canGenerate(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    /**
     * Sous-page de la rubrique « Conformité » (Menu::sections()), jamais une entrée de menu à part.
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(1),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Réglages.

    public static function getMaxMb(): int
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_MAX_MB]);

        return Rules::normalizeMaxMb($values[self::CONFIG_MAX_MB] ?? null);
    }

    public static function saveMaxMb(mixed $value): int
    {
        $maxMb = Rules::normalizeMaxMb($value);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_MAX_MB => $maxMb]);

        return $maxMb;
    }

    // ------------------------------------------------------------------ Périmètre.

    /**
     * Entités actives de la session, proposées comme périmètre : id => nom complet.
     *
     * @return array<int, string>
     */
    public static function perimeterOptions(): array
    {
        $options = [];
        foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $entityId) {
            $options[(int) $entityId] = (string) Dropdown::getDropdownName('glpi_entities', (int) $entityId);
        }
        asort($options);

        return $options;
    }

    public static function isPerimeterAllowed(int $entityId): bool
    {
        return in_array($entityId, array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])), true);
    }

    /**
     * Entités du périmètre : l'entité et ses sous-entités actives dans la session.
     *
     * @return list<int>
     */
    private static function perimeterEntities(int $entityId): array
    {
        $active = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        $sons = array_map('intval', array_values(getSonsOf('glpi_entities', $entityId)));

        return array_values(array_intersect($sons, $active)) ?: [$entityId];
    }

    // ------------------------------------------------------------------ Collecte.

    /**
     * Pièces du périmètre, groupe par groupe (mesures de l'Annexe A, exigences du SMSI, rapports
     * archivés), avec l'état de chaque pièce. Sans calcul d'empreinte (fait à la génération).
     *
     * @return array{groups: list<array<string, mixed>>, documents: array<int, array<string, mixed>>,
     *     summary: array<string, int>, max_mb: int}
     */
    public static function collect(int $entityId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $entities = self::perimeterEntities($entityId);
        $groups = [];
        $documents = [];

        if (Control::canView()) {
            $groups[] = self::controlGroup($entityId, $entities, $today, $documents);
        }
        if (IsmsRequirement::canView()) {
            $groups[] = self::requirementGroup($entities, $today, $documents);
        }
        $groups[] = self::reportGroup($entities, $documents);

        $maxMb = self::getMaxMb();
        $maxBytes = Rules::maxBytes($maxMb);
        $used = 0;
        // Une pièce entre si au moins un de ses rattachements n'est pas expiré.
        foreach ($documents as $id => $document) {
            $validity = $document['all_expired'] ? EvidenceValidity::STATUS_EXPIRED : EvidenceValidity::STATUS_NONE;
            $state = Rules::state($validity, $document['path'] !== '', $document['size'], $used, $maxBytes);
            if ($state === Rules::STATE_INCLUDED) {
                $used += $document['size'];
            }
            $documents[$id]['state'] = $state;
        }
        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                foreach ($row['links'] as $l => $link) {
                    $state = $link['expired'] ? Rules::STATE_EXPIRED : $documents[$link['documents_id']]['state'];
                    $groups[$g]['rows'][$r]['links'][$l]['state'] = $state;
                    $groups[$g]['rows'][$r]['links'][$l]['entry'] = $documents[$link['documents_id']]['entry'];
                }
            }
        }
        // Décompte par pièce (une pièce rattachée à plusieurs mesures ne compte qu'une fois).
        $summary = Rules::summary(array_map(
            static fn (array $d): array => ['state' => $d['state'], 'size' => $d['size']],
            array_values($documents)
        ));

        return ['groups' => $groups, 'documents' => $documents, 'summary' => $summary, 'max_mb' => $maxMb];
    }

    /**
     * @param list<int> $entities
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private static function controlGroup(int $entityId, array $entities, string $today, array &$documents): array
    {
        global $DB;

        $controls = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code', 'applicability', 'implementation_status'],
                'FROM'   => Control::getTable(),
                'WHERE'  => EntityPerimeter::controlCriteria($entityId),
            ]) as $row
        ) {
            $controls[(int) $row['id']] = $row;
        }
        $links = self::linksFor(Control::class, array_keys($controls), $entities, $today, $documents);
        $applicabilities = Control::getApplicabilities();
        $statuses = Control::getImplementationStatuses();
        $byCode = [];
        foreach ($controls as $id => $row) {
            $byCode[(string) $row['code']] = [
                'code'       => (string) $row['code'],
                'title'      => Control::getControlTitle((string) $row['code']),
                'applicable' => (string) $row['applicability'] !== 'no',
                'status'     => (string) $row['applicability'] === 'no'
                    ? (string) ($applicabilities['no'] ?? '')
                    : (string) ($statuses[$row['implementation_status']] ?? $row['implementation_status']),
                'links'      => $links[$id] ?? [],
            ];
        }

        return [
            'key'   => 'controls',
            'title' => __('Annex A controls (Statement of Applicability)', 'grcmanager'),
            'rows'  => self::sortRows($byCode),
        ];
    }

    /**
     * @param list<int> $entities
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private static function requirementGroup(array $entities, string $today, array &$documents): array
    {
        global $DB;

        $requirements = [];
        foreach ($DB->request(['SELECT' => ['id', 'code', 'status'], 'FROM' => IsmsRequirement::getTable()]) as $row) {
            $requirements[(int) $row['id']] = $row;
        }
        $links = self::linksFor(IsmsRequirement::class, array_keys($requirements), $entities, $today, $documents);
        $statuses = IsmsRequirement::getStatuses();
        $byCode = [];
        foreach ($requirements as $id => $row) {
            $byCode[(string) $row['code']] = [
                'code'       => (string) $row['code'],
                'title'      => IsmsRequirement::getClauseTitle((string) $row['code']),
                'applicable' => true,
                'status'     => (string) ($statuses[$row['status']] ?? $row['status']),
                'links'      => $links[$id] ?? [],
            ];
        }

        return [
            'key'   => 'requirements',
            'title' => __('ISMS requirements (clauses 4 to 10)', 'grcmanager'),
            'rows'  => self::sortRows($byCode),
        ];
    }

    /**
     * Rapports PDF déjà archivés par le plugin (SoA approuvées, revues de direction, audits...),
     * regroupés par sous-article.
     *
     * @param list<int> $entities
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private static function reportGroup(array $entities, array &$documents): array
    {
        global $DB;

        $labels = [
            SoaVersion::class           => __('Approved versions of the Statement of Applicability', 'grcmanager'),
            PolicyVersion::class        => __('Approved policy versions', 'grcmanager'),
            ResponsibilityExport::class => __('ISMS responsibility matrix', 'grcmanager'),
            CompetenceExport::class     => __('Competence records', 'grcmanager'),
            Audit::class                => __('Audit reports and audit evidence', 'grcmanager'),
            ManagementReview::class     => __('Management review files', 'grcmanager'),
            AccessReview::class         => __('Access review reports', 'grcmanager'),
        ];
        $rows = [];
        foreach (self::ARCHIVED_REPORTS as $itemtype => $code) {
            $rows[$itemtype] = ['code' => $code, 'title' => $labels[$itemtype], 'applicable' => true,
                'status' => '', 'links' => []];
        }
        $table = RestrictedDocument::TABLE;
        foreach (
            $DB->request([
                'SELECT'     => array_merge(self::documentColumns(), ["$table.itemtype"]),
                'FROM'       => $table,
                'INNER JOIN' => [
                    'glpi_documents' => ['FKEY' => [$table => 'documents_id', 'glpi_documents' => 'id']],
                ],
                'WHERE'      => [
                    "$table.itemtype"           => array_keys(self::ARCHIVED_REPORTS),
                    'glpi_documents.is_deleted' => 0,
                ] + getEntitiesRestrictCriteria('glpi_documents', '', $entities, true),
                'ORDER'      => 'glpi_documents.date_creation DESC',
            ]) as $row
        ) {
            if (!self::registerDocument($row, false, $documents)) {
                continue;
            }
            $rows[(string) $row['itemtype']]['links'][] = self::link($row, false);
        }

        return [
            'key'   => 'reports',
            'title' => __('Reports archived by the plugin', 'grcmanager'),
            'rows'  => array_values($rows),
        ];
    }

    /**
     * Documents rattachés (onglet Documents) aux éléments, avec leur validité éventuelle : seuls
     * ceux des entités du périmètre que la personne peut lire.
     *
     * @param list<int> $itemIds
     * @param list<int> $entities
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, list<array<string, mixed>>> id de l'élément => rattachements
     */
    private static function linksFor(
        string $itemtype,
        array $itemIds,
        array $entities,
        string $today,
        array &$documents
    ): array {
        global $DB;

        if ($itemIds === []) {
            return [];
        }
        $links = Document_Item::getTable();
        $validity = [];
        $evidenceIds = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'items_id', 'documents_id', 'valid_until'],
                'FROM'   => Evidence::getTable(),
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $itemIds],
            ]) as $row
        ) {
            $validity[$row['items_id'] . ':' . $row['documents_id']] = $row['valid_until'];
            $evidenceIds[$row['items_id'] . ':' . $row['documents_id']] = (int) $row['id'];
        }
        // Issue #886 : rythme de renouvellement, pour signaler une preuve récurrente périmée.
        $rhythms = EvidenceRecurrence::forEvidences(array_values($evidenceIds));
        $result = [];
        foreach (
            $DB->request([
                'SELECT'     => array_merge(self::documentColumns(), ["$links.items_id"]),
                'FROM'       => $links,
                'INNER JOIN' => [
                    'glpi_documents' => ['FKEY' => [$links => 'documents_id', 'glpi_documents' => 'id']],
                ],
                'WHERE'      => [
                    "$links.itemtype"           => $itemtype,
                    "$links.items_id"           => $itemIds,
                    'glpi_documents.is_deleted' => 0,
                ] + getEntitiesRestrictCriteria('glpi_documents', '', $entities, true),
                'ORDER'      => 'glpi_documents.name',
            ]) as $row
        ) {
            $validUntil = $validity[$row['items_id'] . ':' . $row['documents_id']] ?? null;
            $expired = EvidenceValidity::status($validUntil, $today) === EvidenceValidity::STATUS_EXPIRED;
            if (!self::registerDocument($row, $expired, $documents)) {
                continue;
            }
            $rhythm = $rhythms[$evidenceIds[$row['items_id'] . ':' . $row['documents_id']] ?? 0] ?? null;
            $recurrence = $rhythm !== null
                ? EvidenceRecurrence::describe($rhythm, $row['date_mod'] ?? null, $today)
                : null;
            $result[(int) $row['items_id']][] = array_merge(self::link($row, $expired, $validUntil), [
                'stale'    => $recurrence !== null && $recurrence['status'] === RecurrenceRules::STATUS_STALE,
                'next_due' => $recurrence['next_due'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function documentColumns(): array
    {
        return [
            'glpi_documents.id AS documents_id',
            'glpi_documents.name',
            'glpi_documents.filename',
            'glpi_documents.filepath',
            'glpi_documents.date_creation',
            'glpi_documents.date_mod',
        ];
    }

    /**
     * Retient le document s'il est lisible (droits GLPI, documents restreints compris). Renvoie
     * faux pour un document que la personne ne peut pas lire : il n'apparaît nulle part.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $documents
     */
    private static function registerDocument(array $row, bool $expired, array &$documents): bool
    {
        $id = (int) $row['documents_id'];
        if (!isset($documents[$id])) {
            $document = new Document();
            if (!$document->can($id, READ)) {
                return false;
            }
            $filepath = (string) ($row['filepath'] ?? '');
            $path = $filepath !== '' ? GLPI_DOC_DIR . '/' . $filepath : '';
            $exists = $path !== '' && is_file($path);
            $documents[$id] = [
                'id'          => $id,
                'name'        => (string) $row['name'],
                'filename'    => (string) ($row['filename'] ?? ''),
                'path'        => $exists ? $path : '',
                'size'        => $exists ? (int) filesize($path) : 0,
                'entry'       => Rules::entryName($id, (string) ($row['filename'] ?? '') ?: (string) $row['name']),
                'all_expired' => true,
                'state'       => '',
                'sha256'      => '',
            ];
        }
        if (!$expired) {
            $documents[$id]['all_expired'] = false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function link(array $row, bool $expired, ?string $validUntil = null): array
    {
        return [
            'documents_id' => (int) $row['documents_id'],
            'name'         => (string) $row['name'],
            'date'         => (string) ($row['date_mod'] ?? $row['date_creation'] ?? ''),
            'valid_until'  => $validUntil,
            'expired'      => $expired,
            'stale'        => false,
            'next_due'     => null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byCode
     * @return list<array<string, mixed>>
     */
    private static function sortRows(array $byCode): array
    {
        $rows = [];
        foreach (Rules::sortCodes(array_map('strval', array_keys($byCode))) as $code) {
            $rows[] = $byCode[$code];
        }

        return $rows;
    }

    /**
     * Libellés et classes des badges par état.
     *
     * @return array<string, array{label: string, tone: string, icon: string}>
     */
    public static function getStates(): array
    {
        return [
            Rules::STATE_INCLUDED  => [
                'label' => __('Included', 'grcmanager'), 'tone' => 'success', 'icon' => 'ti-circle-check',
            ],
            Rules::STATE_EXPIRED   => [
                'label' => __('Expired, not included', 'grcmanager'), 'tone' => 'danger', 'icon' => 'ti-clock-x',
            ],
            Rules::STATE_NO_FILE   => [
                'label' => __('No file', 'grcmanager'), 'tone' => 'secondary', 'icon' => 'ti-file-off',
            ],
            Rules::STATE_TOO_LARGE => [
                'label' => __('Size limit reached, not included', 'grcmanager'),
                'tone'  => 'warning',
                'icon'  => 'ti-file-alert',
            ],
        ];
    }

    // ------------------------------------------------------------------ Génération.

    /**
     * Génère l'archive du périmètre et l'archive comme document restreint. Renvoie l'identifiant du
     * dossier, ou un message d'erreur.
     *
     * @return array{id: int, error: string}
     */
    public static function generate(int $entityId): array
    {
        global $DB;

        if (!self::isPerimeterAllowed($entityId)) {
            return ['id' => 0, 'error' => __('This perimeter is not among your active entities.', 'grcmanager')];
        }
        if (!class_exists(\ZipArchive::class)) {
            return ['id' => 0, 'error' => __('The PHP zip extension is missing on the server.', 'grcmanager')];
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(600);
        }
        $now = date('Y-m-d H:i:s');
        $data = self::collect($entityId);
        $fileName = Rules::fileName($entityId, $now);
        $path = GLPI_TMP_DIR . '/' . $fileName;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['id' => 0, 'error' => __('The archive could not be created.', 'grcmanager')];
        }
        $hashes = [];
        $added = 0;
        foreach ($data['documents'] as $id => $document) {
            if ($document['state'] !== Rules::STATE_INCLUDED) {
                continue;
            }
            $hash = hash_file('sha256', $document['path']);
            if ($hash === false || !$zip->addFile($document['path'], $document['entry'])) {
                $data['documents'][$id]['state'] = Rules::STATE_NO_FILE;
                continue;
            }
            $data['documents'][$id]['sha256'] = $hash;
            $hashes[$document['entry']] = $hash;
            // Les fichiers ajoutés ne sont lus qu'à la fermeture : on ferme régulièrement.
            if (++$added % self::REOPEN_EVERY === 0) {
                $zip->close();
                $zip->open($path, \ZipArchive::CREATE);
            }
        }
        $index = PdfRenderer::renderHtmlToPdf(self::renderIndex($entityId, $data, $now));
        $hashes = ['index.pdf' => hash('sha256', $index)] + $hashes;
        $zip->addFromString('index.pdf', $index);
        $zip->addFromString('SHA256SUMS.txt', Rules::checksums($hashes));
        if (!$zip->close()) {
            return ['id' => 0, 'error' => __('The archive could not be created.', 'grcmanager')];
        }
        $sha256 = (string) hash_file('sha256', $path);
        $size = (int) filesize($path);
        $included = array_keys(array_filter(
            $data['documents'],
            static fn (array $d): bool => $d['state'] === Rules::STATE_INCLUDED
        ));

        // Ligne d'abord : le document est réservé (RestrictedDocument) dès sa création.
        $DB->insert(self::TABLE, [
            'entities_id'    => $entityId,
            'documents_id'   => 0,
            'sha256'         => $sha256,
            'size'           => $size,
            'files_count'    => count($included),
            'expired_count'  => (int) $data['summary'][Rules::STATE_EXPIRED],
            'skipped_count'  => (int) $data['summary'][Rules::STATE_TOO_LARGE],
            'documents'      => (string) json_encode($included),
            'users_id'       => (int) Session::getLoginUserID(),
            'date_creation'  => $now,
        ]);
        $packId = (int) $DB->insertId();

        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                __('Evidence pack - %1$s (%2$s)', 'grcmanager'),
                Dropdown::getDropdownName('glpi_entities', $entityId),
                Html::convDateTime($now)
            ),
            'entities_id'   => $entityId,
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            $DB->delete(self::TABLE, ['id' => $packId]);
            if (is_file($path)) {
                // Archive temporaire construite ci-dessus dans GLPI_TMP_DIR, jamais un chemin reçu.
                unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }

            return ['id' => 0, 'error' => __('The archive could not be stored as a document.', 'grcmanager')];
        }
        RestrictedDocument::registerFor($documentsId, self::class, $packId);
        $DB->update(self::TABLE, ['documents_id' => $documentsId], ['id' => $packId]);
        AuditLog::record('export', self::class, $packId, 0, 'success', [
            'entities_id' => $entityId,
            'files'       => count($included),
        ]);

        return ['id' => $packId, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function renderIndex(int $entityId, array $data, string $now): string
    {
        $documents = $data['documents'];
        foreach ($data['groups'] as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                foreach ($row['links'] as $l => $link) {
                    $doc = $documents[$link['documents_id']];
                    $state = $link['expired'] ? Rules::STATE_EXPIRED : $doc['state'];
                    $data['groups'][$g]['rows'][$r]['links'][$l]['state'] = $state;
                    $data['groups'][$g]['rows'][$r]['links'][$l]['sha256'] = $state === Rules::STATE_INCLUDED
                        ? $doc['sha256'] : '';
                    $data['groups'][$g]['rows'][$r]['links'][$l]['date'] = $link['date'] !== ''
                        ? Html::convDateTime($link['date']) : '';
                    $data['groups'][$g]['rows'][$r]['links'][$l]['valid_until'] = $link['valid_until'] !== null
                        ? Html::convDate($link['valid_until']) : '';
                    $data['groups'][$g]['rows'][$r]['links'][$l]['next_due'] = ($link['next_due'] ?? null) !== null
                        ? Html::convDate($link['next_due']) : '';
                }
            }
        }
        $summary = Rules::summary(array_map(
            static fn (array $d): array => ['state' => $d['state'], 'size' => $d['size']],
            array_values($documents)
        ));

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/evidence_pack_index.html.twig', [
            'report_title'       => self::getTypeName(1),
            'generated_at_label' => sprintf(__('Generated on %s', 'grcmanager'), Html::convDateTime($now)),
            'perimeter'          => Dropdown::getDropdownName('glpi_entities', $entityId),
            'author'             => getUserName((int) Session::getLoginUserID()),
            'groups'             => $data['groups'],
            'summary'            => $summary,
            'size_mb'            => round($summary['bytes'] / 1048576, 1),
            'max_mb'             => $data['max_mb'],
            'states'             => self::getStates(),
        ]);
    }

    // ------------------------------------------------------------------ Lecture des dossiers.

    /**
     * Le dossier se lit par son auteur, ou par qui peut lire chacune des pièces qu'il contient,
     * avec le droit du plugin dans l'entité du périmètre (RestrictedDocument::canReadSource()).
     */
    public static function canRead(int $packId): bool
    {
        global $DB;

        if (!self::canView()) {
            return false;
        }
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $packId]])->current();
        if ($row === null || !Session::haveAccessToEntity((int) $row['entities_id'])) {
            return false;
        }
        if ((int) $row['users_id'] > 0 && (int) $row['users_id'] === (int) Session::getLoginUserID()) {
            return true;
        }
        $ids = json_decode((string) ($row['documents'] ?? '[]'), true);
        foreach (is_array($ids) ? $ids : [] as $documentsId) {
            if (!(new Document())->can((int) $documentsId, READ)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dossiers des entités de la session, le plus récent d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(int $limit = 50): array
    {
        global $DB, $CFG_GLPI;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['entities_id' => array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))],
                'ORDER' => 'id DESC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $documentsId = (int) $row['documents_id'];
            $document = new Document();
            $exists = $documentsId > 0 && $document->getFromDB($documentsId);
            $readable = $exists && self::canRead((int) $row['id']);
            $rows[] = [
                'id'        => (int) $row['id'],
                'date'      => (string) $row['date_creation'],
                'perimeter' => (string) Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                'author'    => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'sha256'    => (string) $row['sha256'],
                'size_mb'   => round((int) $row['size'] / 1048576, 1),
                'files'     => (int) $row['files_count'],
                'expired'   => (int) $row['expired_count'],
                'skipped'   => (int) $row['skipped_count'],
                'exists'    => $exists,
                'url'       => $readable
                    ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $documentsId
                    : '',
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Page.

    public static function showPage(int $entityId): void
    {
        $options = self::perimeterOptions();
        if (!isset($options[$entityId])) {
            $entityId = (int) Session::getActiveEntity();
        }
        $data = self::collect($entityId);
        $expired = [];
        $stale = [];
        $missing = 0;
        foreach ($data['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                if ($group['key'] === 'controls' && $row['applicable'] && $row['links'] === []) {
                    $missing++;
                }
                foreach ($row['links'] as $link) {
                    if ($link['state'] === Rules::STATE_EXPIRED) {
                        $expired[] = ['code' => $row['code'], 'name' => $link['name'],
                            'valid_until' => $link['valid_until']];
                    } elseif ($link['stale']) {
                        $stale[] = ['code' => $row['code'], 'name' => $link['name'], 'next_due' => $link['next_due']];
                    }
                }
            }
        }
        TemplateRenderer::getInstance()->display('@grcmanager/evidence_pack.html.twig', [
            'action'       => self::getSearchURL(),
            'options'      => $options,
            'entity_id'    => $entityId,
            'summary'      => $data['summary'],
            'size_mb'      => round($data['summary']['bytes'] / 1048576, 1),
            'max_mb'       => $data['max_mb'],
            'min_mb'       => Rules::MIN_MAX_MB,
            'limit_mb'     => Rules::MAX_MAX_MB,
            'groups'       => array_map(static fn (array $g): array => [
                'title' => $g['title'],
                'rows'  => count($g['rows']),
                'with'  => count(array_filter($g['rows'], static fn (array $r): bool => $r['links'] !== [])),
            ], $data['groups']),
            'missing'      => $missing,
            'expired'      => $expired,
            'stale'        => $stale,
            'states'       => self::getStates(),
            'can_generate' => self::canGenerate(),
            'packs'        => self::history(),
        ]);
    }
}
