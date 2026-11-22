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
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\PersonalDigestTables;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use GlpiPlugin\Grcmanager\Services\Workspace\PersonalDigestRules as Rules;
use GlpiPlugin\Grcmanager\Services\Workspace\UserSessionScope;
use Html;
use Notification;
use NotificationEvent;
use Session;

/**
 * Issue #877 : récapitulatif personnel par e-mail de ce qui attend chaque personne, repris de
 * « Mon espace GRC », au lieu (ou en plus) des notifications isolées.
 *
 * - Chacun choisit sur « Mon espace GRC » : chaque jour, chaque semaine (par défaut), ou aucun
 *   récapitulatif (seulement les notifications habituelles, au fil de l'eau).
 * - La tâche automatique « personaldigest » (désactivée à l'installation) passe toutes les heures,
 *   examine au plus USERS_PER_RUN personnes qui ne l'ont pas encore été ce jour-là, et envoie la
 *   notification modifiable « GRC Manager - Votre récapitulatif GRC ».
 * - Le contenu est lu avec les droits, les entités et la langue de la personne
 *   (UserSessionScope) ; un récapitulatif vide n'est pas envoyé ; le message ne part qu'à la
 *   personne elle-même (NotificationTargetPersonalDigest::validateSendTo()).
 *
 * Une ligne par personne : fréquence choisie, dernier envoi, dernier examen par la tâche.
 */
class PersonalDigest extends CommonDBTM
{
    public const EVENT = 'personal_digest';
    public const CRON_NAME = 'personaldigest';

    public const SENT = 'sent';
    public const NOTHING_WAITING = 'empty';
    public const NOT_SENT = 'not_sent';

    public static function getTable($classname = null)
    {
        return PersonalDigestTables::DIGESTS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('GRC summary by email', 'GRC summaries by email', $nb, 'grcmanager');
    }

    /**
     * Choix proposés sur « Mon espace GRC », dans les mots de la personne.
     *
     * @return array<string, string>
     */
    public static function getFrequencies(): array
    {
        return [
            Rules::FREQUENCY_DAILY  => __('Every day', 'grcmanager'),
            Rules::FREQUENCY_WEEKLY => __('Every week', 'grcmanager'),
            Rules::FREQUENCY_NEVER  => __('No summary (only the usual notifications, as they happen)', 'grcmanager'),
        ];
    }

    /**
     * Ligne de la personne, ou null si elle n'a encore rien choisi ni reçu.
     *
     * @return array<string, mixed>|null
     */
    public static function rowFor(int $usersId): ?array
    {
        global $DB;

        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['users_id' => $usersId], 'LIMIT' => 1]) as $row) {
            return $row;
        }

        return null;
    }

    public static function frequencyFor(int $usersId): string
    {
        return Rules::normalizeFrequency(self::rowFor($usersId)['frequency'] ?? null);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function store(int $usersId, array $fields): void
    {
        $row = self::rowFor($usersId);
        $digest = new self();
        if ($row === null) {
            $digest->add(['users_id' => $usersId, 'entities_id' => 0] + $fields);
        } else {
            $digest->update(['id' => (int) $row['id']] + $fields);
        }
    }

    public static function saveFrequency(int $usersId, mixed $frequency): bool
    {
        if ($usersId <= 0 || !in_array($frequency, Rules::frequencies(), true)) {
            return false;
        }
        self::store($usersId, ['frequency' => $frequency]);

        return true;
    }

    /**
     * Ce qui attend la personne, lu avec ses droits et ses entités, avec chacun de ses profils.
     *
     * @return list<array{source: string, id: int, name: string, due: ?string, url: string,
     *     detail: string, urgency: string, days_late: int}>
     */
    public static function collect(int $usersId, string $today): array
    {
        return Rules::merge(UserSessionScope::eachProfile(
            $usersId,
            static fn (): array => MySpaceProvider::items($usersId, $today)
        ));
    }

    /**
     * Envoie le récapitulatif de la personne, s'il y a quelque chose à lui signaler.
     *
     * @return string SENT, NOTHING_WAITING ou NOT_SENT (notification absente, inactive ou coupée)
     */
    public static function send(int $usersId, string $today): string
    {
        $items = self::collect($usersId, $today);
        if ($items === []) {
            return self::NOTHING_WAITING;
        }
        if (self::rowFor($usersId) === null) {
            self::store($usersId, ['frequency' => '']);
        }
        $digest = new self();
        if (!$digest->getFromDB((int) (self::rowFor($usersId)['id'] ?? 0))) {
            return self::NOT_SENT;
        }
        $raised = NotificationEvent::raiseEvent(self::EVENT, $digest, [
            'items'     => $items,
            'frequency' => self::frequencyFor($usersId),
        ]);
        if (!$raised) {
            return self::NOT_SENT;
        }
        self::store($usersId, [
            'date_last_sent' => Session::getCurrentTime() ?: date('Y-m-d H:i:s'),
            'items_count'    => count($items),
        ]);

        return self::SENT;
    }

    /**
     * Personnes à examiner ce jour-là : comptes actifs avec une adresse e-mail, qui n'ont pas
     * refusé le récapitulatif et n'ont pas encore été examinés aujourd'hui.
     *
     * @return list<int>
     */
    public static function candidates(string $today, int $limit = Rules::USERS_PER_RUN): array
    {
        global $DB;

        $ids = [];
        $iterator = $DB->request([
            'SELECT'     => ['glpi_users.id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_useremails' => ['FKEY' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']],
            ],
            'LEFT JOIN'  => [
                self::getTable() => ['FKEY' => [self::getTable() => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => [
                'glpi_users.is_active'  => 1,
                'glpi_users.is_deleted' => 0,
                ['OR' => [
                    [self::getTable() . '.id' => null],
                    [self::getTable() . '.frequency' => ['<>', Rules::FREQUENCY_NEVER]],
                ]],
                ['OR' => [
                    [self::getTable() . '.date_last_check' => null],
                    [self::getTable() . '.date_last_check' => ['<', $today]],
                ]],
            ],
            'ORDER'      => 'glpi_users.id',
            'LIMIT'      => $limit,
        ]);
        foreach ($iterator as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public static function cronInfo(string $name): array
    {
        return $name === self::CRON_NAME
            ? ['description' => __('Summary by email of what is waiting for each person', 'grcmanager')]
            : [];
    }

    /**
     * Tâche horaire : chaque personne est examinée une fois par jour ; son récapitulatif part
     * quand sa fréquence le prévoit et qu'il y a quelque chose à lui signaler.
     */
    public static function cronPersonaldigest(CronTask $task): int
    {
        $today = date('Y-m-d');
        $sent = 0;
        foreach (self::candidates($today) as $usersId) {
            $row = self::rowFor($usersId);
            $frequency = Rules::normalizeFrequency($row['frequency'] ?? null);
            if (
                Rules::isDue($frequency, $row['date_last_sent'] ?? null, $today)
                && self::send($usersId, $today) === self::SENT
            ) {
                $sent++;
            }
            self::store($usersId, ['date_last_check' => $today]);
        }
        $task->addVolume($sent);

        return $sent > 0 ? 1 : 0;
    }

    public static function isTaskActive(): bool
    {
        $task = new CronTask();

        return $task->getFromDBbyName(self::class, self::CRON_NAME)
            && (int) $task->fields['state'] !== CronTask::STATE_DISABLE;
    }

    /**
     * Le récapitulatif peut-il partir sur cette instance ? Tâche active, notifications par e-mail
     * de GLPI activées, notification du plugin active.
     */
    public static function canBeSent(): bool
    {
        global $CFG_GLPI;

        $notification = new Notification();

        return self::isTaskActive()
            && !empty($CFG_GLPI['use_notifications']) && !empty($CFG_GLPI['notifications_mailing'])
            && $notification->getFromDBByCrit(['itemtype' => self::class, 'event' => self::EVENT])
            && (int) $notification->fields['is_active'] === 1;
    }

    /**
     * Encadré « Récapitulatif par e-mail » de « Mon espace GRC ».
     */
    public static function showPreference(int $usersId): void
    {
        $row = self::rowFor($usersId);
        $last = (string) ($row['date_last_sent'] ?? '');

        TemplateRenderer::getInstance()->display('@grcmanager/_personal_digest_preference.html.twig', [
            'action'      => MySpace::getSearchURL(),
            'frequencies' => self::getFrequencies(),
            'frequency'   => self::frequencyFor($usersId),
            'last_sent'   => $last !== '' ? (string) Html::convDateTime($last) : '',
            'can_be_sent' => self::canBeSent(),
        ]);
    }
}
