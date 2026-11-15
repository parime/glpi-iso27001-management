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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Compat\Http;

/**
 * Issue #658 : point de passage unique des appels HTTP sortants (Compat\Http), avec le vrai client
 * du cœur (GLPI 11 ou 12). Schémas autres que http(s) et adresses internes refusés avant tout appel
 * réseau ; redirection jamais suivie et en-têtes d'authentification GLPI jamais transmis, vérifiés
 * contre un petit serveur local (php -S) qui journalise chaque requête reçue. Assertions sur des
 * codes d'erreur, jamais sur des textes traduits (CI en anglais, instance de dev en français).
 */
final class HttpTest extends GrcmanagerIntegrationTestCase
{
    /** @var resource|null */
    private $server = null;
    private string $workDir = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->workDir !== '' && is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->workDir);
        }
        parent::tearDown();
    }

    public function testSchemeOtherThanHttpIsRefusedWithoutNetworkCall(): void
    {
        $urls = ['ftp://example.com/x', 'file:///etc/passwd', 'gopher://example.com/', 'dict://example.com:11211/'];
        foreach ($urls as $url) {
            $error = null;
            $response = Http::request($url, [], $error);
            self::assertSame(0, $response['status'], $url);
            self::assertSame(Http::ERROR_SCHEME, $error, $url);
            self::assertSame('', Http::getContent($url));
        }
    }

    public function testInternalAddressIsRefusedByDefaultWithoutNetworkCall(): void
    {
        Http::setAllowPrivateNetworks(false);
        self::assertFalse(Http::allowsPrivateNetworks());

        // Un serveur écoute bel et bien : le refus a lieu avant toute connexion (journal vide).
        $port = $this->startServer();
        $urls = ["http://127.0.0.1:$port/ok", "http://localhost:$port/ok", "http://[::ffff:127.0.0.1]:$port/ok"];
        foreach ($urls as $url) {
            $error = null;
            self::assertSame(0, Http::request($url, [], $error)['status'], $url);
            self::assertSame(Http::ERROR_INTERNAL, $error, $url);
        }
        $error = null;
        self::assertSame('', Http::getContent('http://169.254.169.254/latest/meta-data/', $error));
        self::assertSame(Http::ERROR_INTERNAL, $error);
        self::assertSame([], $this->requests(), 'Aucune requête ne doit atteindre une adresse interne.');

        Http::setAllowPrivateNetworks(true);
        self::assertTrue(Http::allowsPrivateNetworks());
    }

    public function testRedirectIsNotFollowedAndGlpiTokensAreNeverSent(): void
    {
        $port = $this->startServer();
        Http::setAllowPrivateNetworks(true);
        $glpi12 = class_exists(\Glpi\Toolbox\HttpClient::class);

        $error = null;
        $response = Http::request("http://127.0.0.1:$port/redirect", [], $error);
        self::assertSame(0, $response['status']);
        if ($glpi12 && !Http::glpiAllowsPrivateNetworks()) {
            // GLPI 12 : le cœur refuse aussi les réseaux privés tant que la constante ne cite pas le
            // plugin ; le plugin le détecte et n'appelle rien.
            self::assertSame(Http::ERROR_GLPI_CONTEXT, $error);
            self::assertSame([], $this->requests());

            return;
        }
        self::assertStringStartsWith(Http::ERROR_REDIRECT, (string) $error);
        // La cible de la redirection n'est jamais appelée.
        self::assertSame(['/redirect'], array_column($this->requests(), 'uri'));

        $response = Http::request("http://127.0.0.1:$port/ok", [
            'Session-Token' => 'secret-session', 'App-Token' => 'secret-app', 'Cookie' => 'glpi=1', 'X-Test' => 'kept',
        ], $error);
        self::assertNull($error);
        self::assertSame(200, $response['status']);
        self::assertSame('ok', $response['body']);
        $requests = $this->requests();
        $last = end($requests);
        $headers = array_change_key_case((array) ($last['headers'] ?? []));
        self::assertSame('kept', $headers['x-test'] ?? null);
        self::assertArrayNotHasKey('session-token', $headers);
        self::assertArrayNotHasKey('app-token', $headers);
        self::assertArrayNotHasKey('cookie', $headers);
        self::assertStringStartsWith('GLPI-grcmanager/', (string) ($headers['user-agent'] ?? ''));

        // Code d'erreur de l'API renvoyé tel quel (Have I Been Pwned : 404 = aucune fuite).
        $response = Http::request("http://127.0.0.1:$port/missing", [], $error);
        self::assertSame(404, $response['status']);
        self::assertStringStartsWith(Http::ERROR_STATUS, (string) $error);
        self::assertNotContains('/target', array_column($this->requests(), 'uri'));
    }

    /**
     * Petit serveur HTTP local : /redirect renvoie une redirection 302 vers /target, /missing un
     * 404, le reste répond « ok ». Chaque requête est journalisée (chemin et en-têtes).
     */
    private function startServer(): int
    {
        global $CFG_GLPI;
        if (!function_exists('proc_open') || !empty($CFG_GLPI['proxy_name'])) {
            self::markTestSkipped('proc_open unavailable or proxy configured.');
        }
        $this->workDir = sys_get_temp_dir() . '/grcmanager-http-' . bin2hex(random_bytes(4));
        mkdir($this->workDir);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::markTestSkipped('No free local port.');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        file_put_contents($this->workDir . '/router.php', <<<'PHP'
<?php
$log = __DIR__ . '/requests.log';
$entry = json_encode(['uri' => $_SERVER['REQUEST_URI'], 'headers' => getallheaders()]);
file_put_contents($log, $entry . "\n", FILE_APPEND);
if ($_SERVER['REQUEST_URI'] === '/redirect') {
    header('Location: http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/target', true, 302);
    return true;
}
if ($_SERVER['REQUEST_URI'] === '/missing') {
    http_response_code(404);
    return true;
}
echo 'ok';
return true;
PHP);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->workDir . '/router.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->workDir . '/out.log', 'a'],
                2 => ['file', $this->workDir . '/out.log', 'a'],
            ],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);
                // La sonde n'envoie aucune requête HTTP : rien n'est journalisé.
                return $port;
            }
            usleep(100000);
        }
        self::markTestSkipped('The local PHP server did not start.');
    }

    /** @return list<array{uri: string, headers: array<string, string>}> */
    private function requests(): array
    {
        $file = $this->workDir . '/requests.log';
        if (!is_file($file)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(explode("\n", (string) file_get_contents($file)))
        ));
    }
}
