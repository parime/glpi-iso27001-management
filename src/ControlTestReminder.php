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

use Config;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use NotificationEvent;

/**
 * Issue #380, deuxième étape : tâche planifiée des tests d'efficacité
 * (ControlTest::cronControltestreminder()).
 *
 * - Rappel d'un test en retard ou à réaliser dans les 30 jours, une fois par échéance et par état
 *   (colonne `test_reminder_key` de la mesure), par la notification modifiable « GRC Manager -
 *   Test d'efficacité à réaliser » (NotificationTargetControl) : responsable du plan de test et,
 *   selon le réglage, groupe RSSI.
 * - Retrait de l'état « Vérifié » d'une mesure dont le test suivant est en retard : la preuve
 *   d'efficacité est échue. Tracé dans le journal d'audit.
 *
 * Interopérabilité avec Configuration GLPI Auto, sans dépendre de son code : le groupe RSSI est
 * celui qu'il enregistre dans la configuration de ce plugin (AuditTeam::getCisoGroupId()), et la
 * couleur de sa charte des notifications est lue par sa clé publiée (contexte
 * `plugin:configurationglpiauto`, clé `notification_branding_color`), s'il est actif. Sans lui,
 * le groupe RSSI se choisit dans la configuration et la couleur par défaut s'applique.
 */
final class ControlTestReminder
{
    public const EVENT = 'controltest_reminder';
    public const CRON_NAME = 'controltestreminder';

    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const CISO_MODE_KEY = 'controltest_reminder_ciso';

    public const CGA_CONTEXT = NotificationSupport::CGA_CONTEXT;
    public const CGA_BRAND_COLOR_KEY = NotificationSupport::CGA_BRAND_COLOR_KEY;

    public static function cisoMode(): string
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CISO_MODE_KEY]);

        return ControlTestRules::normalizeCisoMode($values[self::CISO_MODE_KEY] ?? '');
    }

    /**
     * @return bool vrai si le réglage a changé
     */
    public static function saveCisoMode(mixed $mode): bool
    {
        $before = self::cisoMode();
        $after = ControlTestRules::normalizeCisoMode($mode);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CISO_MODE_KEY => $after]);

        return $before !== $after;
    }

    /**
     * @return array<string, string> réglage => libellé
     */
    public static function getCisoModes(): array
    {
        return [
            ControlTestRules::CISO_NEVER   => __('Never', 'grcmanager'),
            ControlTestRules::CISO_OVERDUE => __('Overdue tests only', 'grcmanager'),
            ControlTestRules::CISO_ALWAYS  => __('Overdue tests and tests due soon', 'grcmanager'),
        ];
    }

    public static function isCgaActive(): bool
    {
        return NotificationSupport::isCgaActive();
    }

    /**
     * Couleur de la charte des notifications de Configuration GLPI Auto, sinon la couleur par défaut.
     */
    public static function brandColor(): string
    {
        return NotificationSupport::brandColor();
    }

    /**
     * Un utilisateur GLPI peut-il recevoir le rappel d'une mesure ? Il lui faut un profil valable
     * dans l'entité de la notification (mêmes critères que GLPI) qui donne la lecture du plugin.
     * Une adresse sans compte GLPI ne reçoit rien.
     */
    public static function canReceive(int $usersId, int $entitiesId, bool $recursive = false): bool
    {
        return NotificationSupport::canReceive($usersId, $entitiesId, $recursive, [Control::RIGHTNAME => READ]);
    }

    /**
     * @return array{notified: int, withdrawn: int}
     */
    public static function run(string $today): array
    {
        global $DB;

        $result = ['notified' => 0, 'withdrawn' => 0];
        foreach (ControlTest::getSchedule() as $entry) {
            $controlsId = (int) $entry['control']['id'];
            $next = $entry['next'];
            $control = new Control();
            if (!$control->getFromDB($controlsId)) {
                continue;
            }

            $withdrawn = false;
            $current = (string) $control->fields['implementation_status'];
            $status = ControlTestRules::statusWhenDue($current, $next, $today);
            if (
                $status !== null
                && $control->update([
                    'id'                    => $controlsId,
                    'implementation_status' => $status,
                    '_grcmanager_from_test' => true,
                ])
            ) {
                $withdrawn = true;
                $result['withdrawn']++;
                AuditLog::record('update', Control::class, $controlsId, 0, 'success', [
                    'control'               => (string) $control->fields['code'],
                    'implementation_status' => ['verified', $status],
                    'reason'                => 'effectiveness_test_overdue',
                    'next_test_date'        => $next,
                    'last_test'             => (int) $entry['latest']['id'],
                ]);
            }

            $key = ControlTestRules::reminderKey($next, $today);
            if ($key === null || $key === (string) ($control->fields['test_reminder_key'] ?? '')) {
                continue;
            }
            NotificationEvent::raiseEvent(self::EVENT, $control, [
                'due_status' => ControlTestRules::dueStatus($next, $today),
                'withdrawn'  => $withdrawn,
            ]);
            // Écriture directe : un rappel n'est ni une modification ni une revue de la SoA.
            $DB->update(Control::getTable(), ['test_reminder_key' => $key], ['id' => $controlsId]);
            $result['notified']++;
        }

        return $result;
    }
}
