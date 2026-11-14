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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyAcknowledgement;

/**
 * Issue #535 : prise de connaissance des politiques.
 * - « acknowledge » : la personne confirme pour elle-même (toute session ; acknowledge() vérifie
 *   que la ligne est la sienne et que la campagne est en vigueur) ;
 * - toutes les autres actions (public, délai, lancement, relance, clôture) exigent le droit de
 *   modifier la politique.
 */
Session::checkLoginUser();
$usersId = (int) Session::getLoginUserID();

if (isset($_POST['acknowledge'])) {
    if (empty($_POST['confirm_read'])) {
        Session::addMessageAfterRedirect(
            __('Tick the box to confirm that you have read the policy.', 'grcmanager'),
            false,
            ERROR
        );
        Html::back();
    }
    $error = PolicyAcknowledgement::acknowledge((int) ($_POST['lines_id'] ?? 0), $usersId);
    if ($error === '') {
        Session::addMessageAfterRedirect(__('Thank you: your acknowledgement has been recorded.', 'grcmanager'));
    } else {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::back();
}

$policy = new Policy();
$policyId = (int) ($_POST['policies_id'] ?? 0);
if (!$policy->getFromDB($policyId)) {
    throw new BadRequestHttpException();
}
if (!$policy->can($policyId, UPDATE)) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['add_audience'])) {
    $added = PolicyAcknowledgement::addAudience(
        $policyId,
        (string) ($_POST['itemtype'] ?? ''),
        (int) ($_POST['items_id'] ?? -1),
        !empty($_POST['is_recursive'])
    );
    if (!$added) {
        Session::addMessageAfterRedirect(__('Choose a group, a profile or an entity to add.', 'grcmanager'), false, ERROR);
    }
} elseif (isset($_POST['remove_audience'])) {
    PolicyAcknowledgement::removeAudience($policyId, (int) ($_POST['audience_id'] ?? 0));
} elseif (isset($_POST['save_days'])) {
    $policy->update(['id' => $policyId, 'acknowledgement_days' => $_POST['acknowledgement_days'] ?? '']);
} elseif (isset($_POST['launch'])) {
    $count = PolicyAcknowledgement::launch($policy, $usersId);
    if ($count > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('Campaign launched: %d person(s) have been asked to read and confirm the policy.', 'grcmanager'),
            $count
        ));
    } elseif ($count === 0) {
        Session::addMessageAfterRedirect(__('No active account matches the audience of this policy.', 'grcmanager'), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(
            __('A campaign can only be launched on an approved policy with an audience, once per version.', 'grcmanager'),
            false,
            ERROR
        );
    }
} elseif (isset($_POST['remind']) || isset($_POST['close'])) {
    $campaign = PolicyAcknowledgement::getCampaign((int) ($_POST['campaigns_id'] ?? 0));
    if ($campaign === null || (int) $campaign['plugin_grcmanager_policies_id'] !== $policyId) {
        throw new BadRequestHttpException();
    }
    if (isset($_POST['remind'])) {
        $count = PolicyAcknowledgement::sendReminders(date('Y-m-d'), (int) $campaign['id']);
        Session::addMessageAfterRedirect(sprintf(__('%d reminder(s) sent.', 'grcmanager'), $count));
    } elseif (PolicyAcknowledgement::close((int) $campaign['id']) > 0) {
        Session::addMessageAfterRedirect(sprintf(
            __('Campaign closed: the report has been archived as evidence on control %s and on the policy.', 'grcmanager'),
            PolicyAcknowledgement::CONTROL_CODE
        ));
    } else {
        Session::addMessageAfterRedirect(__('The campaign could not be closed or its report could not be archived.', 'grcmanager'), false, ERROR);
    }
}
Html::back();
