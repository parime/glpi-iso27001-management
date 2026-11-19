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

/*
 * Issue #707 : fausse console SentinelOne derrière le serveur intégré de PHP, pour les essais dans
 * le navigateur et les tests d'intégration (données fictives, voir MockSentinelOne).
 *
 *   php -S 0.0.0.0:8099 tests/mock-sentinelone/router.php
 *
 * Avec la variable d'environnement MOCK_S1_LOG, chaque requête reçue (méthode, chemin, présence du
 * jeton) est ajoutée à ce fichier : le jeton lui-même n'est jamais écrit.
 */

require_once dirname(__DIR__) . '/Unit/Mock/MockSentinelOne.php';

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = [];
parse_str((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$headers = function_exists('getallheaders') ? (array) getallheaders() : [];

$log = getenv('MOCK_S1_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'method'    => $_SERVER['REQUEST_METHOD'],
        'path'      => $path,
        'query'     => $query,
        'has_token' => isset(array_change_key_case($headers)['authorization']),
    ]) . "\n", FILE_APPEND);
}

$response = (new \GlpiPlugin\Grcmanager\Tests\Mock\MockSentinelOne())->handle(
    (string) $_SERVER['REQUEST_METHOD'],
    $path,
    array_map('strval', array_filter($query, 'is_scalar')),
    array_map('strval', $headers)
);
http_response_code($response['status']);
header('Content-Type: application/json');
if ($response['retry_after'] > 0) {
    header('Retry-After: ' . $response['retry_after']);
}
echo $response['body'];

return true;
