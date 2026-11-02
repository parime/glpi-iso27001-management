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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\PlaybookStepBase;
use GlpiPlugin\Grcmanager\Services\Incident\PlaybookRules;
use Session;

/**
 * Issue #370 : étape d'une procédure de remédiation (phase, rang, obligatoire ou non). Mêmes droits
 * que la bibliothèque des procédures.
 */
class PlaybookStep extends PlaybookStepBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_playbooksteps';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Playbook step', 'Playbook steps', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-numbers';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getForPlaybook(int $playbooksId): array
    {
        return array_values((new self())->find(
            ['plugin_grcmanager_playbooks_id' => $playbooksId],
            ['rank ASC', 'id ASC']
        ));
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Playbook) || $item->isNewItem() || !Playbook::canView()) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            countElementsInTable(self::getTable(), ['plugin_grcmanager_playbooks_id' => $item->getID()]),
            $item::class,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Playbook)) {
            return false;
        }
        $steps = self::getForPlaybook((int) $item->getID());

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/playbook_steps.html.twig', [
            'item'      => $item,
            'groups'    => PlaybookRules::byPhase($steps),
            'phases'    => Playbook::getPhaseLabels(),
            'next_rank' => $steps === [] ? 10 : ((int) max(array_column($steps, 'rank')) + 10),
            'can_edit'  => $item->canUpdateItem(),
            'form_url'  => self::getFormURL(),
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input['plugin_grcmanager_playbooks_id']);

        return $this->checkInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkInput(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('The step name is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (isset($input['phase']) && !in_array($input['phase'], PlaybookRules::PHASES, true)) {
            $input['phase'] = 'containment';
        }
        if (array_key_exists('is_mandatory', $input)) {
            $input['is_mandatory'] = empty($input['is_mandatory']) ? 0 : 1;
        }
        if (array_key_exists('rank', $input)) {
            $input['rank'] = max(0, (int) $input['rank']);
        }

        return $input;
    }
}
