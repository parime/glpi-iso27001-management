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
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables as Tables;
use GlpiPlugin\Grcmanager\Services\Risk\SupplierPortalRules as Rules;
use GlpiPlugin\Grcmanager\SupplierEvaluation;
use GlpiPlugin\Grcmanager\SupplierEvaluationAnswer;
use GlpiPlugin\Grcmanager\SupplierEvaluationLink;

// Issue #865, lot 2 : page publique du questionnaire, sans session GLPI (Firewall::STRATEGY_NO_CHECK,
// setup.php). Elle s'autorise par le jeton du lien (SupplierEvaluationLink : empreinte seule en
// base, une évaluation, expiration, envois limités, révocable) et n'ouvre que les questions de cette
// évaluation. Fonction désactivée par défaut. Limitation de débit par adresse IP et par jeton ;
// jeton CSRF des formulaires contrôlé par GLPI (session anonyme) ; réponses gardées en brouillon
// jusqu'à leur relecture par un utilisateur GLPI.

header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; img-src \'self\' data:; font-src \'self\'; '
    . 'form-action \'self\'; frame-ancestors \'none\'; base-uri \'none\'');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$render = static function (int $status, array $data): void {
    http_response_code($status);
    TemplateRenderer::getInstance()->display('@grcmanager/supplier_portal.html.twig', $data + [
        'state' => 'invalid',
        'lang'  => substr((string) ($_SESSION['glpilanguage'] ?? 'en_GB'), 0, 2),
    ]);
};

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!SupplierEvaluationLink::isEnabled()) {
    $render(404, []);

    return;
}
if (!SupplierEvaluationLink::hit('ip|' . $ip, SupplierEvaluationLink::LIMIT_IP)) {
    $render(429, ['state' => 'limit']);

    return;
}

$token = $_POST['t'] ?? $_GET['t'] ?? '';
$link = SupplierEvaluationLink::findByToken($token);
if ($link === null) {
    if (!SupplierEvaluationLink::hit('bad|' . $ip, SupplierEvaluationLink::LIMIT_BAD_TOKEN)) {
        $render(429, ['state' => 'limit']);

        return;
    }
    AuditLog::record('access_denied', SupplierEvaluationLink::class, 0, 0, 'denied', ['reason' => 'unknown_token', 'public' => 1]);
    $render(404, []);

    return;
}
$token = (string) $token;
if (!SupplierEvaluationLink::hit('token|' . (string) $link['token_hash'], SupplierEvaluationLink::LIMIT_TOKEN)) {
    $render(429, ['state' => 'limit']);

    return;
}

$evaluation = new SupplierEvaluation();
$status = $evaluation->getFromDB((int) $link[Tables::EVALUATION_FK])
    ? Rules::status($link, date('Y-m-d H:i:s'), $evaluation->isCompleted())
    : Rules::STATUS_CLOSED;
if ($status !== Rules::STATUS_ACTIVE) {
    AuditLog::record('access_denied', SupplierEvaluationLink::class, (int) $link['id'], 0, 'denied', [
        'reason' => $status, 'evaluation' => (int) $link[Tables::EVALUATION_FK], 'public' => 1,
    ]);
    $render(410, ['state' => 'closed']);

    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    SupplierEvaluationLink::recordAccess($link);
    $render(200, SupplierEvaluationLink::portalData($link, $evaluation, $token, [], []));

    return;
}

// Envoi : réponses de cette évaluation seulement, pièces contrôlées comme toute preuve.
$rows = SupplierEvaluationAnswer::getAnswers($evaluation->getID());
$collected = Rules::collect($rows, $_POST['answer'] ?? [], $_POST['comment'] ?? []);
$errors = array_map(
    static fn (string $code): string => sprintf(__('Question %s: choose an answer from the list.', 'grcmanager'), $code),
    $collected['errors']
);
$files = [];
$upload = $_FILES['evidence'] ?? null;
$codes = array_column($rows, 'code', 'id');
if (is_array($upload) && is_array($upload['error'] ?? null)) {
    foreach ($upload['error'] as $answerId => $phpError) {
        if ($phpError === UPLOAD_ERR_NO_FILE || !isset($codes[(int) $answerId])) {
            continue;
        }
        $tmp = $upload['tmp_name'][$answerId] ?? '';
        $name = $upload['name'][$answerId] ?? '';
        $error = count($files) >= Rules::MAX_FILES
            ? __('Too many documents in one sending.', 'grcmanager')
            : SupplierEvaluationLink::evidenceError((int) $phpError, is_string($tmp) ? $tmp : '', $name);
        if ($error !== null) {
            $errors[] = sprintf(__('Question %1$s: %2$s', 'grcmanager'), (string) $codes[(int) $answerId], $error);
            continue;
        }
        $files[(int) $answerId] = ['tmp' => (string) $tmp, 'name' => (string) $name];
    }
}
if ($errors === [] && $collected['answers'] === [] && $files === []) {
    $errors[] = __('Nothing was sent: answer at least one question.', 'grcmanager');
}
if ($errors !== []) {
    $render(422, SupplierEvaluationLink::portalData($link, $evaluation, $token, $collected['answers'], $errors));

    return;
}
if (SupplierEvaluationLink::submit($link, $evaluation, $collected['answers'], $files) <= 0) {
    $render(410, ['state' => 'closed']);

    return;
}
$render(200, ['state' => 'sent']);
