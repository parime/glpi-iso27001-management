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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceProvider;
use GlpiPlugin\Grcmanager\Services\Workspace\MySpaceRules;
use Html;
use Session;

/**
 * Issue #539 : « Mon espace GRC » (front/myspace.php). Une seule page, en lecture seule, avec tout
 * ce qui attend l'utilisateur connecté, trié par échéance ; chaque ligne mène à l'élément. Entrée
 * de menu propre (et non sous-page d'une rubrique) : la barre latérale de GLPI n'affiche que le
 * premier niveau, et c'est là que le nombre d'éléments en attente doit se voir.
 */
class MySpace extends CommonGLPI
{
    /** Nombre d'éléments en attente gardé en session pour le menu, et sa durée de validité. */
    private const SESSION_KEY = 'plugin_grcmanager_myspace_count';
    private const CACHE_SECONDS = 300;

    public static function getTypeName($nb = 0)
    {
        return __('My GRC space', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-user-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/myspace.php';
    }

    /**
     * Toute session : la page ne montre que ce qui est confié à l'utilisateur, source par source
     * selon ses droits (MySpaceProvider).
     */
    public static function canView(): bool
    {
        return Session::getLoginUserID() !== false;
    }

    /**
     * Dans le menu pour qui a un droit de lecture sur le plugin, ou a déjà quelque chose à faire
     * (un responsable de revue des accès ou un approbateur de la SoA n'a souvent aucun droit).
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $hasRight = (bool) Session::haveRight('plugin_grcmanager', READ)
            || (bool) Session::haveRight('plugin_grcmanager_sensitive', READ);
        if (!$hasRight && self::pendingCount() === 0) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Libellé du menu avec le nombre d'éléments en attente, recalculé au plus toutes les
     * CACHE_SECONDS secondes (le menu est affiché sur chaque page).
     */
    public static function menuTitle(): string
    {
        $count = self::pendingCount();

        return $count > 0 ? sprintf(__('%1$s (%2$s)'), self::getTypeName(), $count) : self::getTypeName();
    }

    public static function pendingCount(): int
    {
        $usersId = (int) Session::getLoginUserID();
        if ($usersId <= 0) {
            return 0;
        }
        $cached = $_SESSION[self::SESSION_KEY] ?? null;
        if (
            is_array($cached) && ($cached['users_id'] ?? 0) === $usersId
            && ($cached['time'] ?? 0) > time() - self::CACHE_SECONDS
        ) {
            return (int) $cached['count'];
        }

        return self::remember($usersId, count(MySpaceProvider::items($usersId, date('Y-m-d'))));
    }

    private static function remember(int $usersId, int $count): int
    {
        $_SESSION[self::SESSION_KEY] = ['users_id' => $usersId, 'count' => $count, 'time' => time()];

        return $count;
    }

    /**
     * Libellé et icône de chaque sorte d'élément.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function getSources(): array
    {
        return [
            'risk_review'          => [__('Risk to review', 'grcmanager'), 'ti-alert-triangle'],
            'treatment_action'     => [__('Treatment action', 'grcmanager'), 'ti-checklist'],
            'control_test'         => [__('Control effectiveness test', 'grcmanager'), 'ti-test-pipe'],
            'evidence'             => [__('Evidence to renew', 'grcmanager'), 'ti-file-certificate'],
            'nonconformity'        => [__('Nonconformity', 'grcmanager'), 'ti-alert-octagon'],
            'periodic_review'      => [__('Periodic review', 'grcmanager'), 'ti-repeat'],
            'access_review'        => [__('Access review', 'grcmanager'), 'ti-user-search'],
            'policy_review'        => [__('Policy to review', 'grcmanager'), 'ti-file-text'],
            'training'             => [__('Training to attend', 'grcmanager'), 'ti-school'],
            'training_renewal'     => [__('Training to renew', 'grcmanager'), 'ti-school'],
            'supplier_review'      => [__('Supplier risk to review', 'grcmanager'), 'ti-truck'],
            'obligation_review'    => [__('Obligation to review', 'grcmanager'), 'ti-scale'],
            'soa_approval'         => [__('SoA approval', 'grcmanager'), 'ti-signature'],
            'data_subject_request' => [__('Data subject request', 'grcmanager'), 'ti-user-shield'],
            'audit'                => [__('Audit', 'grcmanager'), 'ti-clipboard-check'],
            'objective'            => [__('Security objective', 'grcmanager'), 'ti-target'],
            'event'                => [__('Compliance event', 'grcmanager'), 'ti-calendar-event'],
        ];
    }

    /**
     * Contenu de la page : synthèse puis liste triée par échéance.
     */
    public static function show(): void
    {
        $usersId = (int) Session::getLoginUserID();
        $items = MySpaceProvider::items($usersId, date('Y-m-d'));
        self::remember($usersId, count($items));
        $sources = self::getSources();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['name' => $item['name'] !== '' ? $item['name'] : '#' . $item['id']] + $item + [
                'label'    => $sources[$item['source']][0] ?? $item['source'],
                'icon'     => $sources[$item['source']][1] ?? 'ti-point',
                'due_text' => $item['due'] !== null ? (string) Html::convDate($item['due']) : '',
            ];
        }

        TemplateRenderer::getInstance()->display('@grcmanager/my_space.html.twig', [
            'rows'         => $rows,
            'summary'      => MySpaceRules::summary($items),
            'horizon_days' => MySpaceRules::HORIZON_DAYS,
        ]);
    }
}
