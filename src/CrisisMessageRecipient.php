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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\CrisisMessageRecipientBase;
use GlpiPlugin\Grcmanager\Install\CrisisCommunicationTables;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules as Rules;
use Group;
use Group_User;
use Session;
use User;
use UserEmail;

/**
 * Issue #616 : liste de diffusion d'un modèle de message de crise. Destinataires internes
 * (utilisateurs et groupes GLPI) et externes : les contacts officiels du contexte de l'organisation
 * (#771), jamais un second annuaire. Droits du modèle (droit général du plugin).
 *
 * Ce qui s'affiche d'un destinataire suit les droits de la personne qui regarde : adresse d'un
 * utilisateur seulement avec le droit de lire les utilisateurs, contact officiel seulement avec le
 * droit du contexte et dans ses entités (et, depuis un incident, dans l'entité de l'incident ou une
 * entité parente) ; un destinataire masqué est seulement compté.
 */
class CrisisMessageRecipient extends CrisisMessageRecipientBase
{
    public const RIGHTNAME = CrisisMessageTemplate::RIGHTNAME;

    public const TEMPLATE_FK = 'plugin_grcmanager_crisismessagetemplates_id';

    public static function getTable($classname = null)
    {
        return CrisisCommunicationTables::RECIPIENTS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Distribution list', 'Distribution lists', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-users-group';
    }

    /**
     * @return array<string, string>
     */
    public static function getRecipientTypes(): array
    {
        return [
            Rules::RECIPIENT_USER    => User::getTypeName(1),
            Rules::RECIPIENT_GROUP   => Group::getTypeName(1),
            Rules::RECIPIENT_CONTACT => OfficialContact::getTypeName(1),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rowsFor(int $templateId): array
    {
        return array_values((new self())->find([self::TEMPLATE_FK => $templateId], ['itemtype ASC', 'id ASC']));
    }

    /**
     * Destinataire affichable, ou null s'il est masqué à la personne (droits, entités) ou supprimé.
     * $entity : entité de l'incident ou de la violation (contacts et groupes de cette entité ou
     * d'une entité parente), null pour la liste du modèle (entités de la session).
     *
     * @param array<string, mixed> $row
     * @return array{key: string, type: string, type_label: string, name: string, address: string,
     *     missing_address: bool, internal: bool}|null
     */
    public static function describe(array $row, ?int $entity): ?array
    {
        global $DB;

        $itemtype = (string) $row['itemtype'];
        $id = (int) $row['items_id'];
        $types = self::getRecipientTypes();
        $base = [
            'key'        => Rules::recipientKey($itemtype, $id),
            'type'       => $itemtype,
            'type_label' => $types[$itemtype] ?? $itemtype,
        ];
        switch ($itemtype) {
            case Rules::RECIPIENT_USER:
                $user = new User();
                if (!$user->getFromDB($id) || !empty($user->fields['is_deleted'])) {
                    return null;
                }
                $canReadUsers = User::canView();
                $email = $canReadUsers ? (string) UserEmail::getDefaultForUser($id) : '';

                return $base + [
                    'name'            => (string) getUserName($id),
                    'address'         => $email,
                    'missing_address' => $canReadUsers && $email === '',
                    'internal'        => true,
                ];
            case Rules::RECIPIENT_GROUP:
                $criteria = ['id' => $id];
                $criteria[] = $entity === null
                    ? getEntitiesRestrictCriteria(Group::getTable(), '', '', true)
                    : getEntitiesRestrictCriteria(Group::getTable(), '', $entity, true);
                $group = $DB->request(['FROM' => Group::getTable(), 'WHERE' => $criteria])->current();
                if ($group === null) {
                    return null;
                }
                $members = countElementsInTable(Group_User::getTable(), ['groups_id' => $id]);

                return $base + [
                    'name'            => (string) $group['completename'],
                    'address'         => sprintf(_n('%d member', '%d members', $members, 'grcmanager'), $members),
                    'missing_address' => $members === 0,
                    'internal'        => true,
                ];
            case Rules::RECIPIENT_CONTACT:
                $contact = self::readableContact($id, $entity);
                if ($contact === null) {
                    return null;
                }

                return $base + [
                    'name'            => (string) $contact['name'],
                    'address'         => (string) ($contact['contact_details'] ?? ''),
                    'missing_address' => trim((string) ($contact['contact_details'] ?? '')) === '',
                    'internal'        => false,
                ];
        }

        return null;
    }

    /**
     * Contact officiel lisible (droit du contexte, entités de la session, hors corbeille), et
     * visible depuis $entity si elle est donnée.
     *
     * @return array<string, mixed>|null
     */
    private static function readableContact(int $id, ?int $entity): ?array
    {
        $where = ['id' => $id];
        if ($entity !== null) {
            $where[] = getEntitiesRestrictCriteria(ContextItem::getTable(), '', $entity, true);
        }
        $rows = OfficialContact::visibleContacts($where);

        return $rows[0] ?? null;
    }

    /**
     * Liste de diffusion d'un modèle telle que la personne peut la voir.
     *
     * @return array{recipients: list<array<string, mixed>>, hidden: int}
     */
    public static function listFor(int $templateId, ?int $entity): array
    {
        $recipients = [];
        $hidden = 0;
        foreach (self::rowsFor($templateId) as $row) {
            $recipient = self::describe($row, $entity);
            if ($recipient === null) {
                $hidden++;
                continue;
            }
            $recipients[] = $recipient + ['id' => (int) $row['id']];
        }

        return ['recipients' => $recipients, 'hidden' => $hidden];
    }

    /**
     * Ajout d'un destinataire depuis l'onglet du modèle : type connu, élément existant et lisible
     * par la personne (contact officiel de ses entités, groupe de ses entités, utilisateur actif).
     */
    public static function addTo(int $templateId, string $itemtype, int $itemsId): bool
    {
        $template = new CrisisMessageTemplate();
        if (!$template->getFromDB($templateId) || !$template->canUpdateItem()) {
            return false;
        }
        if (!in_array($itemtype, Rules::RECIPIENT_TYPES, true) || $itemsId <= 0) {
            return false;
        }
        $candidate = self::describe(['itemtype' => $itemtype, 'items_id' => $itemsId], null);
        if ($candidate === null) {
            return false;
        }
        $existing = [self::TEMPLATE_FK => $templateId, 'itemtype' => $itemtype, 'items_id' => $itemsId];
        if (countElementsInTable(self::getTable(), $existing) > 0) {
            return true;
        }

        return (bool) (new self())->add([
            self::TEMPLATE_FK => $templateId,
            'itemtype'        => $itemtype,
            'items_id'        => $itemsId,
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CrisisMessageTemplate || $item->isNewItem() || !CrisisMessageTemplate::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(1),
            countElementsInTable(self::getTable(), [self::TEMPLATE_FK => $item->getID()]),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CrisisMessageTemplate || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        $list = self::listFor($item->getID(), null);
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/crisis_message_recipients.html.twig', [
            'template_id'  => $item->getID(),
            'recipients'   => $list['recipients'],
            'hidden'       => $list['hidden'],
            'can_edit'     => $item->canUpdateItem(),
            'can_contacts' => ContextItem::canView(),
            'form_url'     => self::getFormURL(),
            'contacts_url' => OfficialContact::getRegisterURL(),
            'contact_condition' => [
                'is_official_contact' => 1,
                'kind'                => ContextItemRules::KIND_PARTY,
                'is_deleted'          => 0,
            ],
        ]);

        return true;
    }
}
