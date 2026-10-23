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

use GlpiPlugin\Grcmanager\Compatibility\Base\EvidenceBase;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceValidity;

/**
 * Issue #145 : date de validité d'un document de preuve rattaché (onglet Documents natif, donc
 * Document_Item) à une mesure de l'Annexe A, un risque fournisseur, un audit ou une politique.
 * Une ligne par (élément, document) : date de fin de validité, responsable du renouvellement,
 * trace de la notification envoyée et du ticket ouvert.
 *
 * Règle commune des issues #139 à #146 : aucun changement automatique de statut. La tâche
 * planifiée `evidencereminder` prévient le responsable avant l'échéance (EvidenceValidity::
 * WARNING_DAYS), puis ouvre un ticket « Renouveler la preuve » une fois la preuve expirée ; la
 * mesure, le fournisseur, l'audit ou la politique ne changent jamais d'état pour autant.
 *
 * Les éléments porteurs sont globaux (sans entité) dans ce plugin ; le document de preuve, lui,
 * appartient à une entité. Une validité suit donc l'entité de son document : la lire exige de
 * pouvoir lire l'élément ET le document, la modifier exige de pouvoir modifier l'élément et lire
 * le document — jamais le seul droit global du plugin.
 */
class PluginGrcmanagerEvidence extends EvidenceBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const EXPIRING_EVENT = 'evidence_expiring';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_evidences';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Validité de preuve', 'Validités des preuves', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-time';
    }

    /**
     * Élément porteur chargé, ou null si le type n'est pas pris en charge ou l'élément introuvable.
     */
    public static function loadParent(string $itemtype, int $itemsId): ?CommonDBTM
    {
        if (!EvidenceValidity::isSupportedItemtype($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return null;
        }
        // Type validé par la liste blanche EvidenceValidity::ITEMTYPES ci-dessus.
        // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
        $parent = new $itemtype();
        return $itemsId > 0 && $parent->getFromDB($itemsId) ? $parent : null;
    }

    public function getParent(): ?CommonDBTM
    {
        return self::loadParent(
            (string) ($this->fields['itemtype'] ?? ''),
            (int) ($this->fields['items_id'] ?? 0)
        );
    }

    public static function canReadDocument(int $documentsId): bool
    {
        $document = new Document();
        return $documentsId > 0 && $document->getFromDB($documentsId) && $document->can($documentsId, READ);
    }

    public function canViewItem(): bool
    {
        $parent = $this->getParent();
        return $parent !== null && $parent->can($parent->getID(), READ)
            && self::canReadDocument((int) $this->fields['documents_id']);
    }

    public function canUpdateItem(): bool
    {
        $parent = $this->getParent();
        return $parent !== null && $parent->can($parent->getID(), UPDATE)
            && self::canReadDocument((int) $this->fields['documents_id']);
    }

    public function canCreateItem(): bool
    {
        return $this->canUpdateItem();
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    /**
     * Le document doit être rattaché à l'élément (onglet Documents) : une validité ne s'attache
     * jamais à un document quelconque désigné par son id.
     */
    public static function isDocumentLinked(string $itemtype, int $itemsId, int $documentsId): bool
    {
        return countElementsInTable(Document_Item::getTable(), [
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
            'documents_id' => $documentsId,
        ]) > 0;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $itemtype = (string) ($input['itemtype'] ?? '');
        $itemsId = (int) ($input['items_id'] ?? 0);
        $documentsId = (int) ($input['documents_id'] ?? 0);
        $parent = self::loadParent($itemtype, $itemsId);
        if ($parent === null || !self::isDocumentLinked($itemtype, $itemsId, $documentsId)) {
            Session::addMessageAfterRedirect(__('Document introuvable sur cet élément.', 'grcmanager'), false, ERROR);
            return false;
        }

        // Entité du document de preuve (les éléments porteurs sont globaux).
        $document = new Document();
        $document->getFromDB($documentsId);
        $input['entities_id'] = (int) ($document->fields['entities_id'] ?? 0);
        $input['is_recursive'] = (int) ($document->fields['is_recursive'] ?? 0);

        return self::normalizeInput($input);
    }

    /**
     * Une nouvelle date de validité (preuve renouvelée) réarme le rappel et le ticket.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepareInputForUpdate($input)
    {
        // L'élément et le document d'une ligne ne changent jamais.
        unset($input['itemtype'], $input['items_id'], $input['documents_id'], $input['entities_id']);
        $input = self::normalizeInput($input);
        $previous = $this->fields['valid_until'] ?? null;
        if (array_key_exists('valid_until', $input) && $input['valid_until'] !== $previous) {
            $input['date_notified'] = null;
            $input['tickets_id'] = 0;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $input): array
    {
        if (array_key_exists('valid_until', $input)) {
            $date = trim((string) $input['valid_until']);
            $input['valid_until'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
        }
        if (array_key_exists('users_id', $input)) {
            $input['users_id'] = max(0, (int) $input['users_id']);
        }

        return $input;
    }

    /**
     * Documents rattachés à l'élément, chacun avec sa validité éventuelle.
     *
     * @return list<array{
     *     documents_id: int, name: string, evidence_id: int, valid_until: ?string, users_id: int,
     *     tickets_id: int
     * }>
     */
    public static function getDocumentsFor(CommonDBTM $parent): array
    {
        global $DB;

        $table = self::getTable();
        $links = Document_Item::getTable();
        $rows = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_documents.id AS documents_id',
                    'glpi_documents.name',
                    "$table.id AS evidence_id",
                    "$table.valid_until",
                    "$table.users_id",
                    "$table.tickets_id",
                ],
                'FROM'      => $links,
                'INNER JOIN' => [
                    'glpi_documents' => ['FKEY' => [$links => 'documents_id', 'glpi_documents' => 'id']],
                ],
                'LEFT JOIN' => [
                    $table => ['ON' => [
                        $table           => 'documents_id',
                        'glpi_documents' => 'id',
                        ['AND' => ["$table.itemtype" => $parent->getType(), "$table.items_id" => $parent->getID()]],
                    ]],
                ],
                // Seulement les documents des entités de l'utilisateur : l'élément porteur est
                // global, ses documents de preuve ne le sont pas.
                'WHERE'     => [
                    "$links.itemtype"           => $parent->getType(),
                    "$links.items_id"           => $parent->getID(),
                    'glpi_documents.is_deleted' => 0,
                ] + getEntitiesRestrictCriteria('glpi_documents', '', '', true),
                'ORDER'     => 'glpi_documents.name',
            ]) as $row
        ) {
            $rows[] = [
                'documents_id' => (int) $row['documents_id'],
                'name'         => (string) $row['name'],
                'evidence_id'  => (int) ($row['evidence_id'] ?? 0),
                'valid_until'  => $row['valid_until'] ?? null,
                'users_id'     => (int) ($row['users_id'] ?? 0),
                'tickets_id'   => (int) ($row['tickets_id'] ?? 0),
            ];
        }

        return $rows;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM
            || $item->isNewItem()
            || !EvidenceValidity::isSupportedItemtype($item->getType())
        ) {
            return '';
        }
        $today = date('Y-m-d');
        $toRenew = count(array_filter(
            self::getDocumentsFor($item),
            static fn (array $doc): bool => in_array(
                EvidenceValidity::status($doc['valid_until'], $today),
                [EvidenceValidity::STATUS_EXPIRING, EvidenceValidity::STATUS_EXPIRED],
                true
            )
        ));

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $toRenew, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || !EvidenceValidity::isSupportedItemtype($item->getType())) {
            return false;
        }
        self::showForParent($item);

        return true;
    }

    public static function statusBadge(?string $validUntil): string
    {
        [$class, $label] = match (EvidenceValidity::status($validUntil, date('Y-m-d'))) {
            EvidenceValidity::STATUS_EXPIRED  => ['bg-red-lt', __('Expirée', 'grcmanager')],
            EvidenceValidity::STATUS_EXPIRING => ['bg-orange-lt', __('À renouveler', 'grcmanager')],
            EvidenceValidity::STATUS_VALID    => ['bg-green-lt', __('Valide', 'grcmanager')],
            default                           => ['bg-secondary-lt', __('Sans date de validité', 'grcmanager')],
        };

        return '<span class="badge ' . $class . '">' . htmlescape($label) . '</span>';
    }

    private static function showForParent(CommonDBTM $parent): void
    {
        $canEdit = $parent->can($parent->getID(), UPDATE);
        $documents = self::getDocumentsFor($parent);
        global $CFG_GLPI;
        $action = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/evidence.form.php';

        echo '<div class="mb-2 text-muted">' . htmlescape(sprintf(
            __(
                'Indiquez jusqu\'à quand chaque document prouve quelque chose (certificat, rapport, '
                . 'attestation). Le responsable est prévenu %d jours avant l\'échéance, puis un ticket '
                . '« Renouveler la preuve » est ouvert ; le statut de l\'élément ne change jamais.',
                'grcmanager'
            ),
            EvidenceValidity::WARNING_DAYS
        )) . '</div>';

        if ($documents === []) {
            echo '<div class="alert alert-info">' . htmlescape(__(
                'Aucun document rattaché : ajoutez d\'abord les preuves dans l\'onglet Documents.',
                'grcmanager'
            )) . '</div>';
            return;
        }

        echo '<table class="table table-sm align-middle"><thead><tr>'
            . '<th>' . htmlescape(__('Document', 'grcmanager')) . '</th>'
            . '<th>' . htmlescape(__('Statut', 'grcmanager')) . '</th>'
            . '<th>' . htmlescape(__('Valide jusqu\'au', 'grcmanager')) . '</th>'
            . '<th>' . htmlescape(__('Responsable du renouvellement', 'grcmanager')) . '</th>'
            . '<th></th></tr></thead><tbody>';
        foreach ($documents as $doc) {
            $formId = 'grcmanager-evidence-' . $doc['documents_id'];
            echo '<tr><td><a href="' . htmlescape(Document::getFormURLWithID($doc['documents_id'])) . '">'
                . htmlescape($doc['name']) . '</a>';
            if ($doc['tickets_id'] > 0) {
                echo '<div class="small"><a href="' . htmlescape(Ticket::getFormURLWithID($doc['tickets_id'])) . '">'
                    . '<i class="ti ti-ticket me-1"></i>'
                    . htmlescape(sprintf(__('Ticket de renouvellement n°%d', 'grcmanager'), $doc['tickets_id']))
                    . '</a></div>';
            }
            echo '</td><td>' . self::statusBadge($doc['valid_until']) . '</td>';
            if (!$canEdit) {
                echo '<td>' . htmlescape((string) Html::convDate($doc['valid_until'])) . '</td>'
                    . '<td>' . htmlescape($doc['users_id'] > 0 ? getUserName($doc['users_id']) : '') . '</td>'
                    . '<td></td></tr>';
                continue;
            }
            echo '<td><input type="date" class="form-control form-control-sm" name="valid_until"'
                . ' form="' . $formId . '" value="' . htmlescape((string) $doc['valid_until']) . '"></td>';
            echo '<td>' . User::dropdown([
                'name'    => 'users_id',
                'value'   => $doc['users_id'],
                'right'   => 'all',
                'entity'  => (int) ($parent->fields['entities_id'] ?? 0),
                'display' => false,
                'rand'    => $doc['documents_id'],
                'specific_tags' => ['form' => $formId],
            ]) . '</td>';
            echo '<td class="text-nowrap">'
                . '<form method="post" action="' . htmlescape($action) . '" id="' . $formId . '">'
                . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
                . Html::hidden('itemtype', ['value' => $parent->getType()])
                . Html::hidden('items_id', ['value' => $parent->getID()])
                . Html::hidden('documents_id', ['value' => $doc['documents_id']])
                . '<button type="submit" name="save" value="1" class="btn btn-sm btn-primary">'
                . '<i class="ti ti-device-floppy"></i></button>';
            if ($doc['evidence_id'] > 0) {
                echo ' <button type="submit" name="purge" value="1" class="btn btn-sm btn-outline-danger"'
                    . ' title="' . htmlescape(__('Retirer la date de validité', 'grcmanager')) . '">'
                    . '<i class="ti ti-x"></i></button>';
            }
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = [
            'id'            => '2',
            'table'         => 'glpi_documents',
            'field'         => 'name',
            'name'          => __('Document', 'grcmanager'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'items_id',
            'name'          => __('Élément', 'grcmanager'),
            'datatype'      => 'specific',
            'additionalfields' => ['itemtype'],
            'massiveaction' => false,
            'nosearch'      => true,
        ];
        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'valid_until',
            'name'     => __('Valide jusqu\'au', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => '5',
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Responsable du renouvellement', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];
        $tab[] = [
            'id'            => '6',
            'table'         => 'glpi_tickets',
            'field'         => 'name',
            'name'          => __('Ticket de renouvellement', 'grcmanager'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '80',
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'items_id') {
            $parent = self::loadParent(
                (string) ($values['itemtype'] ?? ''),
                (int) ($values['items_id'] ?? 0)
            );
            if ($parent === null) {
                return '';
            }
            return '<a href="' . htmlescape($parent::getFormURLWithID($parent->getID())) . '">'
                . htmlescape($parent::getTypeName(1) . ' — ' . $parent->getName()) . '</a>';
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * @return array{description: string}|array{}
     */
    public static function cronInfo($name)
    {
        return $name === 'evidencereminder'
            ? ['description' => __(
                'Prévient le responsable avant l\'expiration d\'une preuve, puis ouvre un ticket de renouvellement',
                'grcmanager'
            )]
            : [];
    }

    public static function cronEvidencereminder(CronTask $task): int
    {
        $result = self::runReminders(date('Y-m-d'));
        $task->addVolume($result['notified'] + $result['tickets']);
        $task->log(sprintf('%d rappel(s), %d ticket(s) de renouvellement.', $result['notified'], $result['tickets']));

        return ($result['notified'] + $result['tickets']) > 0 ? 1 : 0;
    }

    /**
     * @return array{notified: int, tickets: int}
     */
    public static function runReminders(string $today): array
    {
        global $DB;

        $result = ['notified' => 0, 'tickets' => 0];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['NOT' => ['valid_until' => null]]]) as $row) {
            $evidence = new self();
            if (!$evidence->getFromDB((int) $row['id']) || $evidence->getParent() === null) {
                continue;
            }
            if (EvidenceValidity::reminderDue($row['valid_until'], $row['date_notified'], $today)) {
                NotificationEvent::raiseEvent(self::EXPIRING_EVENT, $evidence);
                $evidence->update(['id' => $evidence->getID(), 'date_notified' => date('Y-m-d H:i:s')]);
                $result['notified']++;
            }
            if (EvidenceValidity::ticketDue($row['valid_until'], (int) $row['tickets_id'], $today)) {
                $ticketsId = $evidence->openRenewalTicket();
                if ($ticketsId > 0) {
                    $evidence->update(['id' => $evidence->getID(), 'tickets_id' => $ticketsId]);
                    $result['tickets']++;
                }
            }
        }

        return $result;
    }

    public function getDocumentName(): string
    {
        return (string) Dropdown::getDropdownName('glpi_documents', (int) $this->fields['documents_id']);
    }

    private function openRenewalTicket(): int
    {
        $parent = $this->getParent();
        if ($parent === null) {
            return 0;
        }
        global $CFG_GLPI;
        $parentUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . $parent::getFormURLWithID($parent->getID(), false);
        $content = '<p>' . htmlescape(sprintf(
            __(
                'La preuve « %1$s » de %2$s « %3$s » n\'est plus valide depuis le %4$s. Renouvelez le '
                . 'document (nouveau certificat, rapport ou attestation) puis mettez à jour sa date de '
                . 'validité.',
                'grcmanager'
            ),
            $this->getDocumentName(),
            $parent::getTypeName(1),
            $parent->getName(),
            Html::convDate($this->fields['valid_until'])
        )) . '</p><p><a href="' . htmlescape($parentUrl) . '">' . htmlescape($parentUrl) . '</a></p>';
        $input = [
            'name'        => sprintf(__('Renouveler la preuve « %s »', 'grcmanager'), $this->getDocumentName()),
            'content'     => $content,
            'entities_id' => (int) $this->fields['entities_id'],
            'type'        => Ticket::DEMAND_TYPE,
        ];
        if ((int) $this->fields['users_id'] > 0) {
            $input['_users_id_assign'] = (int) $this->fields['users_id'];
        }

        return (int) (new Ticket())->add($input);
    }
}
