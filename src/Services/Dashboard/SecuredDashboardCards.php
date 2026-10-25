<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use Session;

/**
 * Point d'entrée de toutes les cartes de tableau de bord du plugin (`provider` dans hook.php).
 *
 * Un tableau de bord GLPI est partagé : une carte ajoutée par un administrateur est affichée à
 * tout utilisateur qui voit ce tableau de bord, sans contrôle de droit propre à la carte. Sans ce
 * filtre, un profil sans aucun droit sur le registre des risques, la SoA ou les incidents en lirait
 * quand même les volumes. Ici, chaque appel vérifie le droit de lecture de l'objet compté ; s'il
 * manque, la carte garde sa forme (pour ne pas casser le rendu) mais ses valeurs sont mises à zéro.
 */
final class SecuredDashboardCards
{
    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function __callStatic(string $name, array $arguments): array
    {
        if (method_exists(DashboardCardService::class, $name)) {
            $provider = DashboardCardService::class;
        } elseif (method_exists(SecurityIncidentCardProvider::class, $name)) {
            $provider = SecurityIncidentCardProvider::class;
        } elseif (method_exists(PilotageCards::class, $name)) {
            // Issue #143 : cartes de pilotage.
            $provider = PilotageCards::class;
        } else {
            throw new \BadMethodCallException(sprintf('Unknown dashboard card provider "%s".', $name));
        }
        $isIncidentCard = $provider === SecurityIncidentCardProvider::class
            || stripos($name, 'securityincident') !== false;

        /** @var array<string, mixed> $card */
        $card = $provider::$name(...$arguments);

        $allowed = $isIncidentCard
            ? \PluginGrcmanagerSecurityIncident::canView()
            : Session::haveRight('plugin_grcmanager', READ);

        return $allowed ? $card : self::zeroed($card);
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public static function zeroed(array $card): array
    {
        if (array_key_exists('number', $card)) {
            $card['number'] = 0;
        }
        if (isset($card['data']) && is_array($card['data'])) {
            foreach ($card['data'] as $key => $entry) {
                if (is_array($entry) && array_key_exists('number', $entry)) {
                    $card['data'][$key]['number'] = 0;
                } elseif (is_numeric($entry)) {
                    $card['data'][$key] = 0;
                }
            }
        }

        return $card;
    }
}
