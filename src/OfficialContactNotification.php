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

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\OfficialContactNotificationBase;
use GlpiPlugin\Grcmanager\Install\OfficialContactTables;
use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules as Rules;
use Html;
use Log;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #771, lot 2 : prises de contact tracées avec les contacts officiels (A.5.5, A.5.6), depuis
 * l'onglet « Contacts officiels » d'un incident de sécurité ou d'une violation de données.
 *
 * Pour chaque contact à prévenir, l'onglet montre l'échéance (début de l'événement plus le délai
 * chiffré du contact) et si le contact a été prévenu à temps, en retard, ou si l'organisation a
 * décidé, avec un motif, qu'il n'était pas nécessaire de le prévenir. La fiche du contact liste ses
 * prises de contact : c'est l'historique des relations avec l'autorité ou le groupe d'intérêt.
 *
 * Rien n'est envoyé ni modifié automatiquement : la personne trace ce qu'elle a fait.
 *
 * Droits :
 * - lecture : droit du contexte de l'organisation (droit général du plugin), et incident ou
 *   violation lisible par la personne ;
 * - tracer ou retirer une prise de contact : droit de modifier l'incident ou la violation, et
 *   contact lisible depuis son entité (même entité ou entité parente, fiche récursive).
 * L'auteur de la trace vient toujours de la session, jamais du formulaire.
 */
class OfficialContactNotification extends OfficialContactNotificationBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const CONTACT_FK = 'plugin_grcmanager_contextitems_id';

    /** @var list<class-string<CommonDBTM>> */
    public const ITEM_TYPES = [PluginGrcmanagerSecurityIncident::class, DataBreach::class];

    public static function getTable($classname = null)
    {
        return OfficialContactTables::NOTIFICATIONS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Recorded contact', 'Recorded contacts', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-phone-outgoing';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/officialcontactnotification.form.php';
    }

    /**
     * @return array<string, string>
     */
    public static function decisionLabels(): array
    {
        return [
            Rules::DECISION_NOTIFIED     => __('Contacted or notified', 'grcmanager'),
            Rules::DECISION_NOT_REQUIRED => __('Not required (decision)', 'grcmanager'),
        ];
    }

    /**
     * Libellé, couleur et icône de chaque situation d'un contact pour un événement.
     *
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function noticeInfos(): array
    {
        return [
            Rules::NOTICE_ON_TIME      => [
                'label' => __('Contacted on time', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-circle-check',
            ],
            Rules::NOTICE_LATE         => [
                'label' => __('Contacted late', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-clock-exclamation',
            ],
            Rules::NOTICE_NOT_REQUIRED => [
                'label' => __('Not required (decision recorded)', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-file-check',
            ],
            Rules::NOTICE_PENDING      => [
                'label' => __('To contact', 'grcmanager'),
                'color' => 'blue',
                'icon'  => 'ti ti-phone-outgoing',
            ],
            Rules::NOTICE_OVERDUE      => [
                'label' => __('Deadline passed, nothing recorded', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-alert-octagon',
            ],
        ];
    }

    /**
     * Incident ou violation de ce type et de cet identifiant, ou null.
     */
    public static function parentOf(string $itemtype, int $id): ?CommonDBTM
    {
        if (!in_array($itemtype, self::ITEM_TYPES, true) || $id <= 0) {
            return null;
        }
        $item = new $itemtype();

        return $item->getFromDB($id) ? $item : null;
    }

    /**
     * Début de l'événement, d'où court le délai : détection de l'incident, prise de connaissance de
     * la violation.
     */
    public static function startOf(CommonDBTM $item): ?string
    {
        $field = $item instanceof DataBreach ? 'date_awareness' : 'date';
        $value = (string) ($item->fields[$field] ?? '');

        return $value !== '' ? $value : null;
    }

    /**
     * Contacts officiels lisibles par la personne depuis l'entité de l'objet (même entité ou entité
     * parente, fiche récursive) : ceux qu'elle peut tracer.
     *
     * @param array<int|string, mixed> $where
     * @return list<array<string, mixed>>
     */
    public static function reachableContacts(CommonDBTM $item, array $where = []): array
    {
        $where[] = getEntitiesRestrictCriteria(
            ContextItem::getTable(),
            '',
            (int) ($item->fields['entities_id'] ?? 0),
            true
        );

        return OfficialContact::visibleContacts($where);
    }

    /**
     * Lignes tracées pour cet objet, les plus récentes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsFor(CommonDBTM $item): array
    {
        if ($item->isNewItem()) {
            return [];
        }

        return array_values((new self())->find(
            ['itemtype' => $item::class, 'items_id' => (int) $item->getID()],
            ['date_notified DESC', 'id DESC']
        ));
    }

    /**
     * Prises de contact tracées pour cet objet, telles que la personne peut les voir : le nom d'un
     * contact qu'elle ne peut pas lire est masqué.
     *
     * @return list<array<string, mixed>>
     */
    public static function tracesFor(CommonDBTM $item): array
    {
        $names = [];
        foreach (self::reachableContacts($item) as $contact) {
            $names[(int) $contact['id']] = (string) $contact['name'];
        }
        $labels = self::decisionLabels();
        $traces = [];
        foreach (self::rowsFor($item) as $row) {
            $contactId = (int) $row[self::CONTACT_FK];
            $traces[] = [
                'id'             => (int) $row['id'],
                'contact'        => $names[$contactId] ?? __('Contact not visible to you', 'grcmanager'),
                'contact_url'    => isset($names[$contactId]) ? ContextItem::getFormURLWithID($contactId) : '',
                'decision'       => (string) $row['decision'],
                'decision_label' => $labels[(string) $row['decision']] ?? '',
                'date'           => (string) $row['date_notified'],
                'reference'      => (string) $row['reference'],
                'comment'        => (string) ($row['comment'] ?? ''),
                'author'         => getUserName((int) $row['users_id']),
            ];
        }

        return $traces;
    }

    /**
     * Situation de chaque contact affiché pour cet objet (id du contact => situation, échéance,
     * date de la première prévenance, libellé, couleur, icône).
     *
     * @param list<array<string, mixed>> $contacts lignes des contacts (ContextItem)
     * @return array<int, array<string, mixed>>
     */
    public static function noticesFor(CommonDBTM $item, array $contacts, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $byContact = [];
        foreach (self::rowsFor($item) as $row) {
            $byContact[(int) $row[self::CONTACT_FK]][] = $row;
        }
        $infos = self::noticeInfos();
        $start = self::startOf($item);
        $notices = [];
        foreach ($contacts as $contact) {
            $id = (int) $contact['id'];
            $notice = Rules::noticeState(
                $byContact[$id] ?? [],
                $start,
                Rules::normalizeDelayHours($contact['contact_delay_hours'] ?? 0),
                $now
            );
            $notices[$id] = $notice + $infos[$notice['state']];
        }

        return $notices;
    }

    // ------------------------------------------------------------------ Enregistrement.

    /**
     * Trace une prise de contact. La personne doit pouvoir modifier l'incident ou la violation, et
     * lire le contact depuis son entité. Renvoie l'identifiant de la trace, ou un message d'erreur.
     *
     * @param array<string, mixed> $input
     */
    public static function record(CommonDBTM $item, array $input, ?string $now = null): int|string
    {
        if (
            !in_array($item::class, self::ITEM_TYPES, true)
            || $item->isNewItem()
            || !$item->can($item->getID(), UPDATE)
        ) {
            return __('You are not allowed to record a contact on this item.', 'grcmanager');
        }
        $contactId = (int) ($input[self::CONTACT_FK] ?? 0);
        $contacts = $contactId > 0 ? self::reachableContacts($item, ['id' => $contactId]) : [];
        if ($contacts === []) {
            return __('Choose an official contact from the register.', 'grcmanager');
        }
        $now ??= date('Y-m-d H:i:s');
        $decision = Rules::normalizeDecision($input['decision'] ?? '');
        $date = Rules::normalizeDateTime($input['date_notified'] ?? '');
        $comment = trim((string) ($input['comment'] ?? ''));
        $refusal = Rules::traceRefusal($decision, $date, $comment, self::startOf($item), $now);
        if ($refusal !== null) {
            return self::refusalMessage($refusal);
        }
        $id = (int) (new self())->add([
            'entities_id'    => (int) $item->fields['entities_id'],
            'itemtype'       => $item::class,
            'items_id'       => (int) $item->getID(),
            self::CONTACT_FK => $contactId,
            'decision'       => $decision,
            'date_notified'  => $date,
            'reference'      => mb_substr(trim((string) ($input['reference'] ?? '')), 0, 255),
            'comment'        => $comment,
            'users_id'       => (int) Session::getLoginUserID(),
        ]);
        if ($id <= 0) {
            return __('The contact could not be recorded.', 'grcmanager');
        }
        $message = sprintf(
            __('Official contact %1$s: %2$s on %3$s', 'grcmanager'),
            (string) $contacts[0]['name'],
            self::decisionLabels()[$decision],
            Html::convDateTime((string) $date)
        );
        self::trace($item::class, (int) $item->getID(), $message);
        self::trace(ContextItem::class, $contactId, $message);

        return $id;
    }

    /**
     * Retire une trace saisie par erreur (droit de modifier l'incident ou la violation). Le retrait
     * reste dans l'historique de l'objet et du contact.
     */
    public static function remove(int $id): ?string
    {
        $trace = new self();
        if ($id <= 0 || !$trace->getFromDB($id)) {
            return __('This recorded contact no longer exists.', 'grcmanager');
        }
        $item = self::parentOf((string) $trace->fields['itemtype'], (int) $trace->fields['items_id']);
        if ($item === null || !$item->can($item->getID(), UPDATE)) {
            return __('You are not allowed to record a contact on this item.', 'grcmanager');
        }
        if (!$trace->delete(['id' => $id], true)) {
            return __('The recorded contact could not be removed.', 'grcmanager');
        }
        $message = sprintf(
            __('Recorded contact of %s removed', 'grcmanager'),
            Html::convDateTime((string) $trace->fields['date_notified'])
        );
        self::trace($item::class, (int) $item->getID(), $message);
        self::trace(ContextItem::class, (int) $trace->fields[self::CONTACT_FK], $message);

        return null;
    }

    private static function trace(string $itemtype, int $id, string $message): void
    {
        Log::history($id, $itemtype, [0, '', $message], self::class, Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    private static function refusalMessage(string $refusal): string
    {
        return match ($refusal) {
            Rules::REFUSED_REASON       => __(
                'Give the reason why this contact did not need to be informed.',
                'grcmanager'
            ),
            Rules::REFUSED_DATE         => __('Enter the date and time of the contact.', 'grcmanager'),
            Rules::REFUSED_FUTURE       => __('The date of the contact cannot be in the future.', 'grcmanager'),
            Rules::REFUSED_BEFORE_START => __(
                'The contact cannot be dated before the start of the event.',
                'grcmanager'
            ),
            default                     => __('Say whether the contact was informed or not required.', 'grcmanager'),
        };
    }

    /**
     * Purge d'un incident, d'une violation ou d'un contact : ses traces disparaissent avec lui.
     */
    public static function forgetItem(CommonDBTM $item): void
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return;
        }
        if ($item instanceof ContextItem) {
            $DB->delete(self::getTable(), [self::CONTACT_FK => (int) $item->getID()]);
        } elseif (in_array($item::class, self::ITEM_TYPES, true)) {
            $DB->delete(self::getTable(), ['itemtype' => $item::class, 'items_id' => (int) $item->getID()]);
        }
    }

    // ------------------------------------------------------------------ Historique d'un contact.

    /**
     * Prises de contact avec ce contact, pour les incidents et violations que la personne peut lire
     * (les autres sont seulement comptées).
     *
     * @return array{entries: list<array<string, mixed>>, hidden: int}
     */
    public static function historyForContact(int $contactId): array
    {
        $labels = self::decisionLabels();
        $entries = [];
        $hidden = 0;
        foreach ((new self())->find([self::CONTACT_FK => $contactId], ['date_notified DESC', 'id DESC']) as $row) {
            $item = self::parentOf((string) $row['itemtype'], (int) $row['items_id']);
            if ($item === null || !$item->can($item->getID(), READ)) {
                $hidden++;
                continue;
            }
            $entries[] = [
                'item_type'      => $item::getTypeName(1),
                'item_icon'      => $item::getIcon(),
                'item_name'      => (string) $item->getName(),
                'item_url'       => $item::getFormURLWithID((int) $item->getID()),
                'decision'       => (string) $row['decision'],
                'decision_label' => $labels[(string) $row['decision']] ?? '',
                'date'           => (string) $row['date_notified'],
                'reference'      => (string) $row['reference'],
                'comment'        => (string) ($row['comment'] ?? ''),
                'author'         => getUserName((int) $row['users_id']),
            ];
        }

        return ['entries' => $entries, 'hidden' => $hidden];
    }

    /**
     * Prévenances tracées depuis une date, avec des contacts de ces catégories, dans les entités de
     * la session (preuve des mesures A.5.5 et A.5.6).
     *
     * @param list<string> $categories
     */
    public static function countNotifiedSince(array $categories, string $since): int
    {
        global $DB;

        $table = self::getTable();
        $contacts = ContextItem::getTable();
        if (!$DB->tableExists($table) || $categories === []) {
            return 0;
        }
        $row = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => $table,
            'INNER JOIN' => [$contacts => ['FKEY' => [$contacts => 'id', $table => self::CONTACT_FK]]],
            'WHERE'      => array_merge([
                $table . '.decision'            => Rules::DECISION_NOTIFIED,
                $table . '.date_notified'       => ['>=', $since],
                $contacts . '.contact_category' => $categories,
                $contacts . '.is_deleted'       => 0,
            ], EntityPerimeter::criteria($table, false)),
        ])->current();

        return (int) ($row['cpt'] ?? 0);
    }

    // ------------------------------------------------------------------ Onglet de la fiche du contact.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof ContextItem
            || $item->isNewItem()
            || empty($item->fields['is_official_contact'])
            || !ContextItem::canView()
        ) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            countElementsInTable(self::getTable(), [self::CONTACT_FK => (int) $item->getID()]),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : mêmes contrôles.
        if (!$item instanceof ContextItem || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        $history = self::historyForContact((int) $item->getID());
        TemplateRenderer::getInstance()->display('@grcmanager/official_contact_history_tab.html.twig', [
            'entries'      => $history['entries'],
            'hidden'       => $history['hidden'],
            'register_url' => OfficialContact::getRegisterURL(),
        ]);

        return true;
    }
}
