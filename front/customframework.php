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
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkSync;

// Issue #537 : référentiels personnalisés. Liste, modèle de fichier, analyse d'un fichier (rapport des
// lignes rejetées et aperçu des changements), puis import confirmé. Rien n'est enregistré avant la
// confirmation ; l'analyse est gardée dans la session le temps de confirmer.
Session::checkRight(CustomFramework::RIGHTNAME, READ);

$sessionKey = 'grcmanager_customframework_import';

// Modèle téléchargeable : en-tête et trois exigences fictives.
if (isset($_GET['template'])) {
    $content = CustomFrameworkCsv::template(CustomFramework::templateExamples());
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="grc-custom-framework-template.csv"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

$target = (int) ($_POST['target'] ?? $_GET['target'] ?? 0);
$targetItem = new CustomFramework();
if ($target > 0 && !$targetItem->getFromDB($target)) {
    $target = 0;
}

if (isset($_POST['confirm_import'])) {
    // Droit vérifié avant tout : création d'un référentiel ou nouvelle version d'un existant.
    Session::checkRightsOr(CustomFramework::RIGHTNAME, [CREATE, UPDATE]);
    $token = (string) ($_POST['import_token'] ?? '');
    $pending = $_SESSION[$sessionKey] ?? null;
    unset($_SESSION[$sessionKey]);
    if (!is_array($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
        Session::addMessageAfterRedirect(
            htmlescape(__('This analysis has expired: choose the file again.', 'grcmanager')),
            false,
            ERROR
        );
        Html::redirect(CustomFramework::getSearchURL());
    }
    $frameworkId = (int) $pending['target'];
    $framework = new CustomFramework();
    $info = [
        'version'     => (string) $pending['version'],
        'source'      => (string) $pending['source'],
        'users_id'    => Session::getLoginUserID(),
        'date_import' => $_SESSION['glpi_currenttime'],
    ];
    if ($frameworkId > 0) {
        Session::checkRight(CustomFramework::RIGHTNAME, UPDATE);
        if (!$framework->getFromDB($frameworkId)) {
            Html::redirect(CustomFramework::getSearchURL());
        }
        // Version et source vides : celles déjà enregistrées restent.
        $info = array_filter($info, static fn ($value): bool => $value !== '');
        $framework->update(['id' => $frameworkId] + $info);
    } else {
        Session::checkRight(CustomFramework::RIGHTNAME, CREATE);
        $frameworkId = (int) $framework->add(['name' => (string) $pending['name']] + $info);
        if ($frameworkId <= 0) {
            Html::redirect(CustomFramework::getSearchURL());
        }
    }
    // Plan recalculé sur l'état présent de la base (une autre saisie a pu passer entre-temps).
    $plan = CustomFrameworkSync::plan(CustomFramework::getExistingForSync($frameworkId), $pending['rows']);
    CustomFramework::applyPlan($frameworkId, $plan);
    $summary = CustomFrameworkSync::summary($plan);
    \GlpiPlugin\Grcmanager\AuditLog::record(
        'custom_framework_import',
        CustomFramework::class,
        $frameworkId,
        0,
        'success',
        ['summary' => $summary, 'rejected' => (int) $pending['rejected']]
    );
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __(
            'Import finished: %1$d requirements added, %2$d updated, %3$d restored, %4$d marked as removed.',
            'grcmanager'
        ),
        $summary[CustomFrameworkSync::CREATE],
        $summary[CustomFrameworkSync::UPDATE],
        $summary[CustomFrameworkSync::RESTORE],
        $summary[CustomFrameworkSync::REMOVE]
    )));
    Html::redirect(CustomFramework::getFormURL() . '?id=' . $frameworkId);
}

$analysis = null;
if (isset($_POST['analyse'])) {
    Session::checkRight(CustomFramework::RIGHTNAME, $target > 0 ? UPDATE : CREATE);
    $file = $_FILES['csv_file'] ?? null;
    $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 2 * 1024 * 1024;
    $name = trim((string) ($_POST['name'] ?? ''));
    if (!$uploaded) {
        $message = __('Choose a CSV file of 2 MB at most.', 'grcmanager');
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        Html::redirect(CustomFramework::getSearchURL() . ($target > 0 ? '?target=' . $target : ''));
    }
    if ($target === 0 && $name === '') {
        Session::addMessageAfterRedirect(htmlescape(__('Give the framework a name.', 'grcmanager')), false, ERROR);
        Html::redirect(CustomFramework::getSearchURL());
    }
    $content = (string) file_get_contents((string) $file['tmp_name']);
    if (!mb_check_encoding($content, 'UTF-8')) {
        // Fichier enregistré par un tableur en Windows-1252 (Excel en français).
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $parsed = CustomFrameworkCsv::parse($content, CustomFramework::annexACodes());
    unset($content);
    $plan = CustomFrameworkSync::plan($target > 0 ? CustomFramework::getExistingForSync($target) : [], $parsed['rows']);
    $token = bin2hex(random_bytes(16));
    $_SESSION[$sessionKey] = [
        'token'    => $token,
        'target'   => $target,
        'name'     => mb_substr($name, 0, 255),
        'version'  => mb_substr(trim((string) ($_POST['version'] ?? '')), 0, 255),
        'source'   => mb_substr(trim((string) ($_POST['source'] ?? '')), 0, 255),
        'rows'     => $parsed['rows'],
        'rejected' => count($parsed['errors']),
    ];
    $actions = [
        CustomFrameworkSync::CREATE    => ['bg-green-lt', 'ti ti-plus', __('New', 'grcmanager')],
        CustomFrameworkSync::UPDATE    => ['bg-blue-lt', 'ti ti-pencil', __('Changed', 'grcmanager')],
        CustomFrameworkSync::RESTORE   => ['bg-teal-lt', 'ti ti-arrow-back-up', __('Restored', 'grcmanager')],
        CustomFrameworkSync::UNCHANGED => ['bg-secondary-lt', 'ti ti-equal', __('Unchanged', 'grcmanager')],
        CustomFrameworkSync::REMOVE    => [
            'bg-orange-lt',
            'ti ti-archive',
            __('Removed (kept with its status)', 'grcmanager'),
        ],
    ];
    $fieldLabels = [
        'theme'       => __('Theme', 'grcmanager'),
        'title'       => _x('label', 'Title', 'grcmanager'),
        'description' => __('Description'),
        'annex_a'     => __('Annex A', 'grcmanager'),
        'code'        => __('Code'),
    ];
    $steps = [];
    foreach ($plan as $step) {
        $steps[] = [
            'line'        => $step['line'],
            'code'        => $step['code'],
            'title'       => $step['title'],
            'annex_a'     => isset($step['fields']['annex_a'])
                ? CustomFrameworkSync::decodeAnnexA((string) $step['fields']['annex_a']) : [],
            'class'       => $actions[$step['action']][0],
            'icon'        => $actions[$step['action']][1],
            'label'       => $actions[$step['action']][2],
            'changed'     => implode(', ', array_map(
                static fn (string $field): string => $fieldLabels[$field] ?? $field,
                $step['changed']
            )),
        ];
    }
    $errors = [];
    foreach ($parsed['errors'] as $error) {
        $errors[] = $error + ['message' => CustomFramework::errorLabel($error['reason'], $error['detail'])];
    }
    $analysis = [
        'token'   => $token,
        'name'    => $target > 0 ? (string) $targetItem->fields['name'] : $name,
        'target'  => $target,
        'valid'   => count($parsed['rows']),
        'errors'  => $errors,
        'steps'   => $steps,
        'summary' => CustomFrameworkSync::summary($plan),
    ];
}

$frameworks = [];
foreach (CustomFramework::getList() as $row) {
    $frameworks[] = [
        'id'        => (int) $row['id'],
        'name'      => (string) $row['name'],
        'version'   => (string) $row['version'],
        'active'    => (int) $row['active'],
        'removed'   => (int) $row['removed'],
        'assessed'  => (int) $row['assessed'],
        'enabled'   => (bool) $row['enabled'],
        'imported'  => $row['date_import'] !== null ? Html::convDateTime((string) $row['date_import']) : '',
        'key'       => (string) $row['key'],
    ];
}

$title = CustomFramework::getTypeName(Session::getPluralNumber());
Html::header($title, CustomFramework::getSearchURL(), 'grcmanager', Control::class, CustomFramework::class);
\GlpiPlugin\Grcmanager\Menu::showSection(Control::class, CustomFramework::class);
\GlpiPlugin\Grcmanager\PageHelp::show('customframework');
TemplateRenderer::getInstance()->display('@grcmanager/custom_framework_list.html.twig', [
    'frameworks'    => $frameworks,
    'analysis'      => $analysis,
    'target'        => $target,
    'target_name'   => $target > 0 ? (string) $targetItem->fields['name'] : '',
    'page_url'      => CustomFramework::getSearchURL(),
    'form_url'      => CustomFramework::getFormURL(),
    'template_url'  => CustomFramework::getSearchURL() . '?template=1',
    'assessment_url' => \GlpiPlugin\Grcmanager\FrameworkAssessment::getSearchURL(),
    'can_create'    => Session::haveRight(CustomFramework::RIGHTNAME, CREATE),
    'can_update'    => Session::haveRight(CustomFramework::RIGHTNAME, UPDATE),
    'max_rows'      => CustomFrameworkCsv::MAX_ROWS,
]);
Html::footer();
