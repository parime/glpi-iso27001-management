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
}
