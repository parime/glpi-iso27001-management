<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility;

/**
 * Outgoing HTTP request working on GLPI 11 and 12: GLPI 12 removed Toolbox::getURLContent() in
 * favour of Glpi\Toolbox\HttpClient, which does not exist in GLPI 11. Both honour GLPI's proxy
 * configuration.
 */
final class Http
{
    /**
     * @return string The response body, or '' on failure (same contract as the former
     *                Toolbox::getURLContent()), $error then describing the cause.
     */
    public static function getContent(string $url, ?string &$error = null): string
    {
        if (class_exists(\Glpi\Toolbox\HttpClient::class)) {
            try {
                return (new \Glpi\Toolbox\HttpClient())
                    ->get($url, ['headers' => ['User-Agent' => 'GLPI-grcmanager'], 'timeout' => 10])
                    ->getContent();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                return '';
            }
        }

        // Délai TOTAL (le cœur ne fixe qu'un délai de connexion) : un hôte qui accepte la connexion
        // sans jamais répondre bloquerait sinon la page.
        return (string) \Toolbox::getURLContent($url, $error, 0, [CURLOPT_TIMEOUT => 10]);
    }

    /**
     * Requête GET avec en-têtes, pour une API qui répond par son code HTTP (404 « rien », 429 « trop de
     * requêtes »...). Ne lève pas d'exception : status 0 et $error en cas d'échec réseau.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, retry_after: int}
     */
    public static function request(string $url, array $headers, ?string &$error = null): array
    {
        $headers += ['User-Agent' => 'GLPI-grcmanager'];
        if (class_exists(\Glpi\Toolbox\HttpClient::class)) {
            try {
                $response = (new \Glpi\Toolbox\HttpClient())->get($url, ['headers' => $headers, 'timeout' => 10]);
            } catch (\Throwable $e) {
                // GLPI 12 lève une exception sur 3xx, 4xx et 5xx, qui porte la réponse (interface
                // HttpExceptionInterface de Symfony, absente de GLPI 11) ; sinon, échec réseau.
                if (!method_exists($e, 'getResponse')) {
                    $error = $e->getMessage();

                    return ['status' => 0, 'body' => '', 'retry_after' => 0];
                }
                $response = $e->getResponse();
            }
            $responseHeaders = $response->getHeaders(false);

            return [
                'status'      => $response->getStatusCode(),
                'body'        => $response->getContent(false),
                'retry_after' => (int) ($responseHeaders['retry-after'][0] ?? 0),
            ];
        }

        try {
            $response = \Toolbox::getGuzzleClient(['timeout' => 10, 'http_errors' => false])
                ->get($url, ['headers' => $headers]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();

            return ['status' => 0, 'body' => '', 'retry_after' => 0];
        }

        return [
            'status'      => $response->getStatusCode(),
            'body'        => (string) $response->getBody(),
            'retry_after' => (int) $response->getHeaderLine('Retry-After'),
        ];
    }
}
