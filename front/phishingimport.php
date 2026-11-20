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
use GlpiPlugin\Grcmanager\PhishingCampaign;
use GlpiPlugin\Grcmanager\PhishingResult;
use GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules;

// Issue #604 : import des résultats d'une campagne de simulation d'hameçonnage (fichier CSV, puis
// correspondance des colonnes). Il faut pouvoir modifier la campagne (droit du plugin et entité) et
// le droit des registres sensibles en modification : les résultats sont nominatifs. Le jeton CSRF
// est contrôlé par GLPI sur tout POST. Aucune valeur du fichier n'est évaluée ni renvoyée telle
// quelle dans un tableur.
Session::checkRight(PhishingCampaign::$rightname, READ);

if (isset($_GET['example'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="phishing-results-example.csv"');
    readfile(dirname(__DIR__) . '/resources/phishing-results-example.csv');

    return;
}

$campaign = new PhishingCampaign();
$campaignId = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($campaignId <= 0 || !$campaign->getFromDB($campaignId) || !PhishingResult::canImport($campaign)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$back = PhishingCampaign::getFormURLWithID($campaignId) . '&forcetab=' . urlencode(PhishingResult::class . '$1');
$action = PhishingResult::getImportURL();
$tmpKey = 'grcmanager_phishing_import';

$pending = $_SESSION[$tmpKey] ?? null;
$pendingPath = is_array($pending) && (int) ($pending['campaign'] ?? 0) === $campaignId ? (string) $pending['path'] : '';
$dropPending = static function () use (&$pendingPath, $tmpKey): void {
    // Chemin créé par le serveur (nom aléatoire, gardé en session) et vérifié sous GLPI_TMP_DIR :
    // aucune partie ne vient de l'utilisateur.
    if (
        $pendingPath !== '' && is_file($pendingPath)
        && str_starts_with(realpath($pendingPath) ?: '', realpath(GLPI_TMP_DIR) ?: '/nonexistent')
    ) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($pendingPath);
    }
    unset($_SESSION[$tmpKey]);
};

if (isset($_POST['cancel'])) {
    $dropPending();
    Html::redirect($back);
}

if (isset($_POST['upload'])) {
    $dropPending();
    $file = $_FILES['phishing_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        Session::addMessageAfterRedirect(htmlescape(__('The file could not be received.', 'grcmanager')), false, ERROR);
        Html::redirect($back);
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ((int) $file['size'] > PhishingRules::MAX_BYTES) {
        Session::addMessageAfterRedirect(htmlescape(__('The file is too large.', 'grcmanager')), false, ERROR);
        Html::redirect($back);
    }
    $raw = (string) file_get_contents((string) $file['tmp_name']);
    if (!in_array($extension, ['csv', 'txt'], true) || !PhishingRules::looksLikeText($raw)) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Choose a CSV file (text separated by commas or semicolons).',
            'grcmanager'
        )), false, ERROR);
        Html::redirect($back);
    }
    $stored = GLPI_TMP_DIR . '/grcmanager-phishing-' . bin2hex(random_bytes(12)) . '.csv';
    move_uploaded_file((string) $file['tmp_name'], $stored);
    $_SESSION[$tmpKey] = ['path' => $stored, 'name' => basename((string) $file['name']), 'campaign' => $campaignId];
    $pending = $_SESSION[$tmpKey];
    $pendingPath = $stored;
}

$valid = $pendingPath !== '' && is_readable($pendingPath)
    && str_starts_with(realpath($pendingPath) ?: '', realpath(GLPI_TMP_DIR) ?: '/nonexistent');
if (!$valid) {
    $dropPending();
    Session::addMessageAfterRedirect(htmlescape(__('The file could not be received.', 'grcmanager')), false, ERROR);
    Html::redirect($back);
}
$parsed = PhishingRules::parse((string) file_get_contents($pendingPath));

if (isset($_POST['import'])) {
    $mapping = PhishingRules::sanitizeMapping((array) ($_POST['mapping'] ?? []), $parsed['headers']);
    if (!isset($mapping['identifier'])) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Choose the column that holds the email address or the login.',
            'grcmanager'
        )), false, ERROR);
    } else {
        $result = PhishingResult::import($campaign, $parsed['rows'], $mapping, (string) $pending['name']);
        $dropPending();
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __(
                '%1$d line(s) read: %2$d person(s) matched, %3$d line(s) not matched, %4$d empty line(s) ignored.',
                'grcmanager'
            ),
            $result['total'],
            $result['matched'],
            $result['unmatched'],
            $result['ignored']
        )));
        Html::redirect($back);
    }
}

$labels = [
    'identifier' => __('Email address or login', 'grcmanager'),
    'status'     => __('Status (GoPhish export)', 'grcmanager'),
    'sent'       => __('Email received', 'grcmanager'),
    'opened'     => __('Opened', 'grcmanager'),
    'clicked'    => __('Link clicked', 'grcmanager'),
    'submitted'  => __('Data entered', 'grcmanager'),
    'reported'   => __('Reported', 'grcmanager'),
];
Html::header(
    PhishingCampaign::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\AccessReview::class,
    PhishingCampaign::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('phishingimport');
TemplateRenderer::getInstance()->display('@grcmanager/phishing_import.html.twig', [
    'action'       => $action,
    'campaign'     => $campaign->fields,
    'back'         => $back,
    'file_name'    => (string) $pending['name'],
    'row_count'    => count($parsed['rows']),
    'truncated'    => $parsed['truncated'],
    'max_rows'     => PhishingRules::MAX_ROWS,
    'headers'      => $parsed['headers'],
    'mapping'      => isset($_POST['import'])
        ? PhishingRules::sanitizeMapping((array) ($_POST['mapping'] ?? []), $parsed['headers'])
        : PhishingRules::guessMapping($parsed['headers']),
    'labels'       => $labels,
    'preview'      => array_slice($parsed['rows'], 0, 5),
]);
Html::footer();
