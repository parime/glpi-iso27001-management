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
 * manque, la carte garde sa forme (pour ne pas casser le rendu) mais perd toutes ses données,
 * voir zeroed().
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

    /** Clés de présentation conservées sur une carte vidée : aucune ne porte de donnée. */
    private const PRESENTATION_KEYS = ['label', 'icon', 'color', 'alt'];

    /**
     * Carte sans aucune donnée tirée des tables du plugin, quelle que soit la forme du widget
     * (revue de sécurité 2.11.0 : remettre à zéro les seules clés « number » laissait passer les
     * séries d'une courbe et les libellés d'une liste). Liste blanche : seules les clés de
     * présentation sont gardées ; un nombre devient 0, une série ou une liste devient vide.
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public static function zeroed(array $card): array
    {
        $empty = array_intersect_key($card, array_flip(self::PRESENTATION_KEYS));
        if (array_key_exists('number', $card)) {
            $empty['number'] = 0;
        }
        if (array_key_exists('data', $card)) {
            $data = is_array($card['data']) ? $card['data'] : [];
            $empty['data'] = array_key_exists('labels', $data) || array_key_exists('series', $data)
                ? ['labels' => [], 'series' => []]
                : [];
        }

        return $empty;
    }
}
