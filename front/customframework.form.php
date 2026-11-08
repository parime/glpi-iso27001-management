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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\CustomRequirement;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkAssessmentRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;

// Issue #537 : un référentiel personnalisé, ses exigences et leur auto-évaluation.
global $CFG_GLPI;

Session::checkRight(CustomFramework::RIGHTNAME, READ);

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$framework = new CustomFramework();
if ($id <= 0 || !$framework->getFromDB($id)) {
    throw new NotFoundHttpException();
}
$formUrl = CustomFramework::getFormURL() . '?id=' . $id;

if (isset($_POST['update'])) {
    Session::checkRight(CustomFramework::RIGHTNAME, UPDATE);
    $framework->update([
        'id'      => $id,
        'name'    => (string) ($_POST['name'] ?? ''),
        'version' => (string) ($_POST['version'] ?? ''),
        'source'  => (string) ($_POST['source'] ?? ''),
        'comment' => (string) ($_POST['comment'] ?? ''),
    ]);
    Html::redirect($formUrl);
}

// Activation : même réglage, et même droit, que les cases de la configuration (issue #403).
if (isset($_POST['toggle_framework'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    $key = $framework->getFrameworkKey();
    $enable = !empty($_POST['enable']);
    if (FrameworkConfig::isEnabled($key) !== $enable) {
        FrameworkConfig::setEnabled($key, $enable);
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => [
            $enable ? 'frameworks_enabled' : 'frameworks_disabled' => [$key],
        ]]);
    }
    Html::redirect($formUrl);
}

if (isset($_POST['save_statuses'])) {
    Session::checkRight(CustomFramework::RIGHTNAME, UPDATE);
    $statuses = (array) ($_POST['status'] ?? []);
    $comments = (array) ($_POST['comment_requirement'] ?? []);
    $requirement = new CustomRequirement();
    $changed = 0;
    // Seules les exigences de ce référentiel sont modifiables d'ici.
    foreach (CustomFramework::getRequirements($id) as $row) {
        $requirementId = (int) $row['id'];
        if (!array_key_exists($requirementId, $statuses) && !array_key_exists($requirementId, $comments)) {
            continue;
        }
        $input = ['id' => $requirementId];
        if (array_key_exists($requirementId, $statuses)) {
            $input['status'] = (string) $statuses[$requirementId];
        }
        if (array_key_exists($requirementId, $comments)) {
            $input['comment'] = (string) $comments[$requirementId];
        }
        if (
            ($input['status'] ?? $row['status']) !== (string) $row['status']
            || ($input['comment'] ?? (string) $row['comment']) !== (string) $row['comment']
        ) {
            $requirement->update($input);
            $changed++;
        }
    }
    Session::addMessageAfterRedirect(htmlescape(sprintf(__('%d requirements updated.', 'grcmanager'), $changed)));
    Html::redirect($formUrl);
}

if (isset($_POST['purge'])) {
    Session::checkRight(CustomFramework::RIGHTNAME, PURGE);
    $framework->delete(['id' => $id], true);
    \GlpiPlugin\Grcmanager\AuditLog::record('custom_framework_purge', CustomFramework::class, $id);
    Html::redirect(CustomFramework::getSearchURL());
}

$statuses = CustomRequirement::getStatuses();
$controlRows = FrameworkAssessment::controls(date('Y-m-d'));
$controlIds = [];
foreach ($controlRows as $code => $control) {
    $controlIds[$code] = $control['id'];
}
$groups = [];
$removed = [];
$counts = array_fill_keys(array_keys($statuses), 0);
foreach (CustomFramework::getRequirements($id) as $row) {
    $status = array_key_exists((string) $row['status'], $statuses) ? (string) $row['status'] : '';
    $controls = [];
    foreach ($row['annex_a_list'] as $code) {
        $controls[] = ['code' => $code, 'id' => $controlIds[$code] ?? null];
    }
    $requirement = [
        'id'          => (int) $row['id'],
        'code'        => (string) $row['code'],
        'title'       => (string) $row['title'],
        'description' => (string) ($row['description'] ?? ''),
        'controls'    => $controls,
        'status'      => $status,
        'comment'     => (string) ($row['comment'] ?? ''),
        'removed_on'  => $row['date_removed'] !== null ? Html::convDate((string) $row['date_removed']) : '',
    ];
    if ((int) $row['is_removed'] === 1) {
        $removed[] = $requirement;
        continue;
    }
    $counts[$status]++;
    $theme = (string) $row['theme'] !== '' ? (string) $row['theme'] : __('Without theme', 'grcmanager');
    $groups[$theme][] = $requirement;
}

// Taux calculé comme pour les référentiels intégrés : mesures de l'Annexe A citées par les exigences.
$key = $framework->getFrameworkKey();
$assessment = null;
foreach (PilotageCards::customFrameworkFilters() as [$filterKey, $covers]) {
    if ($filterKey === $key) {
        $assessment = FrameworkAssessmentRules::assess($controlRows, $covers);
    }
}
$active = array_sum($counts);
$assessed = $active - $counts[CustomRequirement::STATUS_NONE];
$applicable = $active - $counts[CustomRequirement::STATUS_NOT_APPLICABLE] - $counts[CustomRequirement::STATUS_NONE];

Html::header(
    CustomFramework::getTypeName(1),
    CustomFramework::getSearchURL(),
    'grcmanager',
    Control::class,
    CustomFramework::class
);
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, CustomFramework::class);
TemplateRenderer::getInstance()->display('@grcmanager/custom_framework_form.html.twig', [
    'framework'       => $framework->fields,
    'enabled'         => FrameworkConfig::isEnabled($key),
    'framework_key'   => $key,
    'groups'          => $groups,
    'removed'         => $removed,
    'statuses'        => $statuses,
    'counts'          => $counts,
    'active'          => $active,
    'assessed'        => $assessed,
    'self_rate'       => $applicable > 0
        ? (int) round(100 * ($counts[CustomRequirement::STATUS_COMPLIANT] + 0.5 * $counts[CustomRequirement::STATUS_PARTIAL]) / $applicable)
        : null,
    'assessment'      => $assessment,
    'imported'        => $framework->fields['date_import'] !== null
        ? Html::convDateTime((string) $framework->fields['date_import']) : '',
    'imported_by'     => (int) $framework->fields['users_id'] > 0 ? getUserName((int) $framework->fields['users_id']) : '',
    'form_url'        => CustomFramework::getFormURL(),
    'list_url'        => CustomFramework::getSearchURL(),
    'control_url'     => Control::getFormURL(),
    'assessment_url'  => FrameworkAssessment::getSearchURL() . '?framework=' . $key,
    'referentials_url' => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/referentiels.php#grcmanager-' . $key . '-body',
    'can_update'      => Session::haveRight(CustomFramework::RIGHTNAME, UPDATE),
    'can_purge'       => Session::haveRight(CustomFramework::RIGHTNAME, PURGE),
    'can_toggle'      => Session::haveRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE),
]);
Html::footer();
