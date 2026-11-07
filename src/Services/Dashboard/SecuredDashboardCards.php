<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Dashboard;

use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
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
        $provider = self::providerOf($name);

        /** @var array<string, mixed> $card */
        $card = $provider::$name(...$arguments);

        return self::canShow($name) ? $card : self::zeroed($card);
    }

    /**
     * @return class-string
     */
    private static function providerOf(string $name): string
    {
        if (method_exists(DashboardCardService::class, $name)) {
            return DashboardCardService::class;
        }
        if (method_exists(SecurityIncidentCardProvider::class, $name)) {
            return SecurityIncidentCardProvider::class;
        }
        if (method_exists(PilotageCards::class, $name)) {
            // Issue #143 : cartes de pilotage.
            return PilotageCards::class;
        }
        throw new \BadMethodCallException(sprintf('Unknown dashboard card provider "%s".', $name));
    }

    /**
     * Carte tirée des incidents de sécurité : droit des incidents, et module actif.
     */
    public static function isIncidentCard(string $name): bool
    {
        return method_exists(SecurityIncidentCardProvider::class, $name)
            && !method_exists(DashboardCardService::class, $name)
            || stripos($name, 'securityincident') !== false;
    }

    /**
     * Issue #143 : la carte peut-elle être proposée et affichée à l'utilisateur connecté ? Droit de
     * lecture de ce qu'elle compte, module des incidents actif, et référentiel actif (PR #418).
     * Sert au catalogue des cartes (hook.php) comme à chaque calcul (__callStatic()).
     */
    public static function canShow(string $name): bool
    {
        if (self::isIncidentCard($name)) {
            return self::incidentCardsAllowed();
        }
        if (self::isContinuityCard($name)) {
            // Issue #377 : processus métier, sous leur droit dédié (la carte des processus critiques
            // est sensible), pas le droit général du SMSI.
            return (bool) Session::haveRight('plugin_grcmanager_continuity', READ);
        }
        if (self::isSensitiveCard($name)) {
            // Registres sensibles (risques, risques fournisseurs, plans de traitement, tendances) :
            // leur droit dédié, pas le seul droit général du SMSI.
            return (bool) Session::haveRight('plugin_grcmanager_sensitive', READ);
        }
        if (!Session::haveRight('plugin_grcmanager', READ)) {
            return false;
        }

        return self::frameworkAvailable($name, FrameworkConfig::enabled());
    }

    /**
     * Module et cartes des incidents actifs, et droit de lire au moins une partie des incidents.
     */
    private static function incidentCardsAllowed(): bool
    {
        $flags = SecurityIncidentModuleConfig::load();

        return $flags['securityincident_enabled'] && $flags['securityincident_dashboard_enabled']
            && \PluginGrcmanagerSecurityIncident::canView();
    }

    /** Référentiels dont dépend une carte : elle disparaît quand tous sont désactivés. */
    private const FRAMEWORK_CARDS = [
        'complianceByFramework' => [
            FrameworkSelection::ISO27001, FrameworkSelection::NIS2,
            FrameworkSelection::ANSSI, FrameworkSelection::NIST_CSF, FrameworkSelection::CIS_CONTROLS,
            FrameworkSelection::DORA, FrameworkSelection::ISO27701, FrameworkSelection::ISO22301,
            FrameworkSelection::ISO42001, FrameworkSelection::PCI_DSS, FrameworkSelection::SOC2,
        ],
    ];

    /**
     * @param list<string> $enabled référentiels actifs
     */
    public static function frameworkAvailable(string $name, array $enabled): bool
    {
        $needed = self::FRAMEWORK_CARDS[$name] ?? null;

        return $needed === null || array_intersect($needed, $enabled) !== [];
    }

    /**
     * Cartes du plugin que l'utilisateur connecté peut voir (catalogue « Ajouter une carte » et
     * rendu) : une carte retirée ici n'est jamais calculée, GLPI affiche à sa place une carte vide.
     *
     * @param array<string, array<string, mixed>> $cards
     * @return array<string, array<string, mixed>>
     */
    public static function filterCatalog(array $cards): array
    {
        $prefix = self::class . '::';
        // Calculé une fois : le catalogue est relu pour chaque carte affichée.
        $incidents = self::incidentCardsAllowed();

        return array_filter($cards, static function (array $card) use ($prefix, $incidents): bool {
            $provider = (string) ($card['provider'] ?? '');
            if (!str_starts_with($provider, $prefix)) {
                return true;
            }
            $name = substr($provider, strlen($prefix));

            return self::isIncidentCard($name) ? $incidents : self::canShow($name);
        });
    }
    /**
     * Issue #377 : carte tirée des processus métier (continuité).
     */
    public static function isContinuityCard(string $name): bool
    {
        return str_starts_with($name, 'businessProcess');
    }

    /**
     * Carte tirée des registres sensibles : risques, risques fournisseurs, plans de traitement, et
     * tendances / indicateurs qui comptent les risques ouverts.
     */
    public static function isSensitiveCard(string $name): bool
    {
        return preg_match('/risk|supplier|trend|indicators/i', $name) === 1;
    }

    /**
     * Clés de présentation conservées sur une carte vidée : aucune ne porte de donnée (« date » :
     * heure du calcul, que le widget « articleList » de GLPI lit toujours).
     */
    private const PRESENTATION_KEYS = ['label', 'icon', 'color', 'alt', 'date'];

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
