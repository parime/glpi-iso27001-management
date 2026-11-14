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
use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskRegisterImport;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterCsv;
use GlpiPlugin\Grcmanager\Services\Risk\RiskRegisterSync;

// Issue #602 : import CSV d'un registre des risques tenu dans un tableur. Modèle de fichier, analyse à
// blanc (lignes rejetées et changements prévus), puis import confirmé ; export au même format pour
// l'aller-retour avec le tableur. Rien n'est enregistré avant la confirmation ; l'analyse est gardée
// dans la session le temps de confirmer. Registre sensible : droit « Registres sensibles ».
// Les pages front sont incluses depuis une méthode du routeur GLPI : $CFG_GLPI n'y est visible qu'après
// une déclaration globale explicite.
global $CFG_GLPI;

Session::checkRight(Risk::RIGHTNAME, READ);

$sessionKey = 'grcmanager_risk_import';
$pageUrl = $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/riskimport.php';

$sendCsv = static function (string $content, string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
};

// Modèle téléchargeable : en-tête et trois risques fictifs.
if (isset($_GET['template'])) {
    $sendCsv(
        RiskRegisterCsv::build(RiskRegisterCsv::COLUMNS, RiskRegisterImport::templateExamples()),
        'grc-risk-register-template.csv'
    );
    exit;
}

// Export du registre au format du modèle (plus les niveaux calculés, ignorés à l'import).
if (isset($_GET['export'])) {
    $rows = RiskRegisterImport::exportRows();
    SensitiveNotification::logExport(Risk::class, Risk::getTypeName(2) . ' (CSV)');
    AuditLog::record('export', Risk::class, 0, 0, 'success', ['format' => 'csv', 'risks' => count($rows)]);
    $sendCsv(
        RiskRegisterCsv::build(array_merge(RiskRegisterCsv::COLUMNS, RiskRegisterCsv::COMPUTED_COLUMNS), $rows),
        'grc-risk-register-' . date('Y-m-d') . '.csv'
    );
    exit;
}

$canCreate = Session::haveRight(Risk::RIGHTNAME, CREATE);
$canUpdate = Session::haveRight(Risk::RIGHTNAME, UPDATE);

$filterRights = static fn (array $plan): array => RiskRegisterImport::filterRights($plan, $canCreate, $canUpdate);

if (isset($_POST['confirm_import'])) {
    Session::checkRightsOr(Risk::RIGHTNAME, [CREATE, UPDATE]);
    $token = (string) ($_POST['import_token'] ?? '');
    $pending = $_SESSION[$sessionKey] ?? null;
    unset($_SESSION[$sessionKey]);
    if (!is_array($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
        Session::addMessageAfterRedirect(
            htmlescape(__('This analysis has expired: choose the file again.', 'grcmanager')),
            false,
            ERROR
        );
        Html::redirect($pageUrl);
    }
    // Plan recalculé sur l'état présent de la base (une autre saisie a pu passer entre-temps).
    RiskRegisterImport::lookups(true);
    $plan = $filterRights(RiskRegisterSync::plan(RiskRegisterImport::existing(), $pending['rows']));
    $result = RiskRegisterImport::apply($plan['steps']);
    AuditLog::record('import', Risk::class, 0, 0, 'success', [
        'file'      => (string) $pending['file'],
        'created'   => $result['create'],
        'updated'   => $result['update'],
        'unchanged' => $result['unchanged'],
        'failed'    => $result['failed'],
        'rejected'  => (int) $pending['rejected'] + count($plan['errors']),
    ]);
    \Glpi\Event::log(0, Risk::class, 4, 'tools', sprintf(
        __('%1$s imports the risk register: %2$d risks added, %3$d updated.', 'grcmanager'),
        (string) ($_SESSION['glpiname'] ?? ''),
        $result['create'],
        $result['update']
    ));
    Session::addMessageAfterRedirect(htmlescape(sprintf(
        __('Import finished: %1$d risks added, %2$d updated, %3$d unchanged.', 'grcmanager'),
        $result['create'],
        $result['update'],
        $result['unchanged']
    )));
    if ($result['failed'] !== []) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Not saved, refused by the register rules (see the messages above): %s', 'grcmanager'),
            implode(', ', $result['failed'])
        )), false, WARNING);
    }
    Html::redirect(Risk::getSearchURL());
}

$analysis = null;
if (isset($_POST['analyse'])) {
    Session::checkRightsOr(Risk::RIGHTNAME, [CREATE, UPDATE]);
    $file = $_FILES['csv_file'] ?? null;
    $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 2 * 1024 * 1024;
    if (!$uploaded) {
        $message = __('Choose a CSV file of 2 MB at most.', 'grcmanager');
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        Html::redirect($pageUrl);
    }
    $scale = (int) ($_POST['scale'] ?? 4);
    $lookups = RiskRegisterImport::lookups(true);
    $parsed = RiskRegisterCsv::parse(
        RiskRegisterCsv::toUtf8((string) file_get_contents((string) $file['tmp_name'])),
        $lookups,
        $scale
    );
    $plan = $filterRights(RiskRegisterSync::plan(RiskRegisterImport::existing(), $parsed['rows']));
    $errors = array_merge($parsed['errors'], $plan['errors']);
    usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
    $kept = array_column($plan['steps'], 'line');
    $token = bin2hex(random_bytes(16));
    $_SESSION[$sessionKey] = [
        'token'    => $token,
        'file'     => mb_substr(basename((string) ($file['name'] ?? '')), 0, 255),
        'rows'     => array_values(array_filter(
            $parsed['rows'],
            static fn (array $row): bool => in_array($row['line'], $kept, true)
        )),
        'rejected' => count($errors),
    ];
    $actions = [
        RiskRegisterSync::CREATE    => ['bg-green-lt', 'ti ti-plus', __('Added', 'grcmanager')],
        RiskRegisterSync::UPDATE    => ['bg-blue-lt', 'ti ti-pencil', __('Changed', 'grcmanager')],
        RiskRegisterSync::UNCHANGED => ['bg-secondary-lt', 'ti ti-equal', __('Unchanged', 'grcmanager')],
    ];
    $labels = RiskRegisterImport::columnLabels();
    $steps = [];
    $statusChanges = 0;
    foreach ($plan['steps'] as $step) {
        $changes = [];
        foreach ($step['changes'] as $field => $change) {
            $changes[] = [
                'field'  => $labels[$field] ?? $field,
                'old'    => RiskRegisterImport::displayValue($field, $change['old']),
                'new'    => RiskRegisterImport::displayValue($field, $change['new']),
                'status' => $field === 'status',
            ];
            if ($field === 'status' && $step['action'] === RiskRegisterSync::UPDATE) {
                $statusChanges++;
            }
        }
        $steps[] = [
            'line'      => $step['line'],
            'reference' => $step['reference'],
            'title'     => $step['title'],
            'url'       => $step['id'] > 0 ? Risk::getFormURLWithID($step['id']) : '',
            'create'    => $step['action'] === RiskRegisterSync::CREATE,
            'class'     => $actions[$step['action']][0],
            'icon'      => $actions[$step['action']][1],
            'label'     => $actions[$step['action']][2],
            'changes'   => $changes,
        ];
    }
    $rejected = [];
    foreach ($errors as $error) {
        $rejected[] = $error + ['message' => RiskRegisterImport::errorLabel($error['reason'], $error['detail'])];
    }
    $ignored = array_values(array_diff(
        RiskRegisterCsv::COLUMNS,
        $parsed['columns']
    ));
    $analysis = [
        'token'          => $token,
        'file'           => $_SESSION[$sessionKey]['file'],
        'errors'         => $rejected,
        'steps'          => $steps,
        'summary'        => RiskRegisterSync::summary($plan['steps']),
        'status_changes' => $statusChanges,
        'missing'        => array_map(static fn (string $column): string => $labels[$column] ?? $column, $ignored),
        'scale'          => $scale,
    ];
}

$title = __('Import or export the risk register', 'grcmanager');
Html::header($title, $pageUrl, 'grcmanager', Risk::class);
\GlpiPlugin\Grcmanager\Menu::showSection(Risk::class, Risk::class);
\GlpiPlugin\Grcmanager\PageHelp::show('riskimport');
TemplateRenderer::getInstance()->display('@grcmanager/risk_import.html.twig', [
    'analysis'     => $analysis,
    'page_url'     => $pageUrl,
    'list_url'     => Risk::getSearchURL(),
    'template_url' => $pageUrl . '?template=1',
    'export_url'   => $pageUrl . '?export=1',
    'can_import'   => $canCreate || $canUpdate,
    'max_rows'     => RiskRegisterCsv::MAX_ROWS,
    'scales'       => RiskRegisterCsv::SCALES,
    'probabilities' => implode(', ', Risk::getProbabilities()),
    'impacts'       => implode(', ', Risk::getImpacts()),
    'treatments'    => implode(', ', array_filter(Risk::getTreatments())),
    'statuses'      => implode(', ', Risk::getStatuses()),
    'categories'    => implode(', ', Risk::getCategories()),
]);
Html::footer();
