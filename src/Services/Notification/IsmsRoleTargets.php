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

namespace GlpiPlugin\Grcmanager\Services\Notification;

use GlpiPlugin\Grcmanager\IsmsRoles;

/**
 * Issue #1054 : destinataires « rôles du SMSI » d'une notification : adresse générique du RSSI,
 * groupe et adresse générique du DPO. Réservé aux notifications au contenu minimal (un nombre et un
 * lien, lus ensuite avec les droits de la personne) : une adresse générique n'est pas un compte
 * GLPI, aucun droit ne peut être vérifié pour elle. Les notifications qui filtrent leurs
 * destinataires par droit (validateSendTo()) gardent le seul groupe.
 *
 * @phpstan-require-extends \NotificationTarget
 */
trait IsmsRoleTargets
{
    /** À appeler depuis addAdditionalTargets(). */
    protected function addIsmsRoleTargets(bool $withDpo): void
    {
        $this->addTarget(IsmsRoles::TARGET_CISO_EMAIL, __('CISO generic address (GRC Manager)', 'grcmanager'));
        if ($withDpo) {
            $this->addTarget(IsmsRoles::TARGET_DPO_GROUP, __('DPO group (GRC Manager)', 'grcmanager'));
            $this->addTarget(IsmsRoles::TARGET_DPO_EMAIL, __('DPO generic address (GRC Manager)', 'grcmanager'));
        }
    }

    /**
     * À appeler depuis addSpecificTargets() : ajoute les destinataires si la cible est l'une des
     * cibles des rôles du SMSI (rien si le groupe ou l'adresse n'est pas choisi).
     *
     * @param array<string, mixed> $data
     */
    protected function addIsmsRoleRecipients(array $data): void
    {
        switch ((int) ($data['items_id'] ?? 0)) {
            case IsmsRoles::TARGET_CISO_EMAIL:
                $this->addIsmsRoleAddress(IsmsRoles::email(IsmsRoles::ROLE_CISO));
                break;

            case IsmsRoles::TARGET_DPO_EMAIL:
                $this->addIsmsRoleAddress(IsmsRoles::email(IsmsRoles::ROLE_DPO));
                break;

            case IsmsRoles::TARGET_DPO_GROUP:
                $groupsId = IsmsRoles::groupId(IsmsRoles::ROLE_DPO);
                if ($groupsId > 0) {
                    $this->addForGroup(0, $groupsId);
                }
                break;
        }
    }

    private function addIsmsRoleAddress(string $email): void
    {
        global $CFG_GLPI;

        if ($email === '') {
            return;
        }
        $this->addToRecipientsList([
            'email'    => $email,
            'language' => (string) ($CFG_GLPI['language'] ?? ''),
            'users_id' => 0,
        ]);
    }
}
