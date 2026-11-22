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

use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceRules;
use GlpiPlugin\Grcmanager\Services\Workspace\PersonalDigestRules as Rules;
use Html;
use NotificationTarget;
use Override;
use User;
use UserEmail;

/**
 * Issue #877 : récapitulatif personnel (PersonalDigest::send()). Le message ne part qu'à la
 * personne dont c'est le récapitulatif, même si un administrateur ajoute d'autres destinataires à
 * la notification : son contenu a été lu avec ses droits à elle. Les libellés sont produits dans
 * la langue de chaque modèle (GLPI charge la langue du destinataire avant addDataForTemplate()).
 */
class NotificationTargetPersonalDigest extends NotificationTarget
{
    // Hors de la plage des cibles du cœur (voir NotificationTargetEvidence::TARGET_RESPONSIBLE).
    public const TARGET_PERSON = 1877;

    #[Override]
    public function getEvents()
    {
        return [
            PersonalDigest::EVENT => __('Your GRC summary', 'grcmanager'),
        ];
    }

    #[Override]
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_PERSON, __('Person to whom the summary is addressed', 'grcmanager'));
    }

    private function ownerId(): int
    {
        return $this->obj instanceof PersonalDigest ? (int) ($this->obj->fields['users_id'] ?? 0) : 0;
    }

    #[Override]
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) !== self::TARGET_PERSON) {
            return;
        }
        $usersId = $this->ownerId();
        $user = new User();
        if ($usersId <= 0 || !$user->getFromDB($usersId)) {
            return;
        }
        $email = UserEmail::getDefaultForUser($usersId);
        if (empty($email)) {
            return;
        }
        $this->addToRecipientsList([
            'language' => $user->fields['language'] ?: ($GLOBALS['CFG_GLPI']['language'] ?? 'en_GB'),
            'name'     => formatUserName(
                0,
                $user->fields['name'],
                $user->fields['realname'],
                $user->fields['firstname']
            ),
            'email'    => $email,
            'users_id' => $usersId,
        ]);
    }

    /**
     * Seule la personne concernée reçoit son récapitulatif ; elle le reçoit même s'il est envoyé
     * depuis sa propre session (bouton « M'envoyer mon récapitulatif maintenant »), sauf si elle a
     * coupé ses notifications.
     */
    #[Override]
    public function validateSendTo($event, array $infos, $notify_me = false, $emitter = null)
    {
        $usersId = (int) ($infos['users_id'] ?? 0);
        if ($usersId <= 0 || $usersId !== $this->ownerId()) {
            return false;
        }

        return parent::validateSendTo($event, $infos, true, $emitter);
    }

    #[Override]
    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        /** @var list<array{source: string, id: int, name: string, due: ?string, url: string, detail: string, urgency: string, days_late: int}> $items */
        $items = $options['items'] ?? [];
        $frequency = Rules::normalizeFrequency($options['frequency'] ?? null);
        $overdue = count(array_filter(
            $items,
            static fn (array $item): bool => $item['urgency'] === MySpaceRules::URGENCY_OVERDUE
        ));
        $total = count($items);

        $title = $frequency === Rules::FREQUENCY_DAILY
            ? __('Your daily GRC summary', 'grcmanager')
            : __('Your weekly GRC summary', 'grcmanager');
        $headline = sprintf(
            _n('%d item is waiting for you', '%d items are waiting for you', $total, 'grcmanager'),
            $total
        );
        if ($overdue > 0) {
            $headline = sprintf(__('%1$s, %2$s', 'grcmanager'), $headline, sprintf(__('%d overdue', 'grcmanager'), $overdue));
        }
        $rootDoc = (string) ($CFG_GLPI['root_doc'] ?? '');
        $urlBase = (string) ($CFG_GLPI['url_base'] ?? '');

        $this->data['##personaldigest.subject##']  = sprintf(__('%1$s: %2$s'), $title, $headline);
        $this->data['##personaldigest.title##']    = $title;
        $this->data['##personaldigest.headline##'] = $headline;
        $this->data['##personaldigest.total##']    = (string) $total;
        $this->data['##personaldigest.overdue##']  = (string) $overdue;
        $this->data['##personaldigest.more##'] = $total > Rules::MAX_LISTED ? sprintf(
            __('%d more items in My GRC space.', 'grcmanager'),
            $total - Rules::MAX_LISTED
        ) : '';
        $this->data['##personaldigest.url##'] = Rules::absoluteUrl(MySpace::getSearchURL(), $rootDoc, $urlBase);
        $this->data['##personaldigest.explanation##'] = __(
            'This summary lists what is waiting for you in GRC Manager, the most urgent first. It only shows what '
                . 'you are allowed to see. Nothing is changed automatically.',
            'grcmanager'
        );
        $this->data['##personaldigest.howtochange##'] = __(
            'To receive it every day, every week or not at all, open My GRC space.',
            'grcmanager'
        );

        $sources = MySpace::getSources();
        $lines = [];
        foreach (array_slice($items, 0, Rules::MAX_LISTED) as $item) {
            $lines[] = [
                '##digestitem.type##'   => $sources[$item['source']][0] ?? $item['source'],
                '##digestitem.name##'   => $item['name'] !== '' ? $item['name'] : '#' . $item['id'],
                '##digestitem.detail##' => $item['detail'] !== '' ? sprintf(__('(%s)', 'grcmanager'), $item['detail']) : '',
                '##digestitem.due##'    => $item['due'] !== null
                    ? (string) Html::convDate($item['due'])
                    : __('No due date', 'grcmanager'),
                '##digestitem.late##'   => $item['urgency'] === MySpaceRules::URGENCY_OVERDUE
                    ? sprintf(__('%d day(s) late', 'grcmanager'), $item['days_late'])
                    : '',
                '##digestitem.url##'    => Rules::absoluteUrl($item['url'], $rootDoc, $urlBase),
            ];
        }
        // Une ligne par élément pour la boucle ##FOREACHdigestitems## de GLPI, qui accepte une liste de
        // tableaux ici, contrairement au type déclaré de la propriété.
        // @phpstan-ignore assign.propertyType
        $this->data['digestitems'] = $lines;

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    #[Override]
    public function getTags()
    {
        $tags = [
            'personaldigest.subject'  => __('Subject', 'grcmanager'),
            'personaldigest.title'    => __('Title', 'grcmanager'),
            'personaldigest.headline' => __('Summary', 'grcmanager'),
            'personaldigest.total'    => __('Items waiting for you', 'grcmanager'),
            'personaldigest.overdue'  => __('Overdue items', 'grcmanager'),
            'personaldigest.more'     => __('Items not listed', 'grcmanager'),
            'personaldigest.url'      => __('My GRC space page URL', 'grcmanager'),
            'personaldigest.explanation' => __('What this summary contains', 'grcmanager'),
            'personaldigest.howtochange' => __('How to change the frequency', 'grcmanager'),
            'digestitem.type'         => __('Type', 'grcmanager'),
            'digestitem.name'         => __('Item', 'grcmanager'),
            'digestitem.detail'       => __('Details', 'grcmanager'),
            'digestitem.due'          => __('Due date', 'grcmanager'),
            'digestitem.late'         => __('Delay', 'grcmanager'),
            'digestitem.url'          => __('URL'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        $this->addTagToList([
            'tag' => 'digestitems', 'label' => __('Items waiting for you', 'grcmanager'), 'value' => false,
            'foreach' => true,
        ]);
        asort($this->tag_descriptions);
    }
}
