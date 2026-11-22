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

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareModelEolStore;
use GlpiPlugin\Grcmanager\Services\Lifecycle\HardwareWarrantyInventory;
use GlpiPlugin\Grcmanager\Services\Lifecycle\WarrantyRules;
use Session;

/**
 * Issue #1039 : fin de vie déclarée par le constructeur d'un modèle de matériel (issue #782, lot 2),
 * sortie de la configuration du plugin : c'est une donnée du parc, pas un réglage.
 *
 * - Onglet « Fin de vie constructeur » sur les fiches des modèles de GLPI (ComputerModel,
 *   NetworkEquipmentModel, PrinterModel, PhoneModel, PeripheralModel, MonitorModel…), là où
 *   l'administrateur du parc le cherche ;
 * - saisie groupée de tous les modèles en service dans Parc > Sécurité du parc
 *   (front/hardwaremodeleol.php) ;
 * - le seuil d'alerte reste dans la configuration (commun avec la fin de support).
 *
 * Droits inchangés : lecture avec le droit du plugin, saisie avec le droit de le modifier (celui de
 * l'ancien bloc de la configuration). Rien n'est écrit dans les modèles de GLPI. Ancre sans table.
 */
class HardwareModelEol extends CommonGLPI
{
    public const RIGHTNAME = Installer::RIGHT_NAME;

    /** Ancien identifiant du bloc de la configuration, redirigé vers la saisie groupée. */
    public const OLD_ANCHOR = 'grc-hardware-eol-config';

    public static function getTypeName($nb = 0)
    {
        return __('Manufacturer end of life of hardware models', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-x';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function canEdit(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/hardwaremodeleol.php';
    }

    /**
     * @return array<string, string>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => __('Hardware end of life', 'grcmanager'),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Classes de modèle qui portent l'onglet, et le type de matériel de chacune.
     *
     * @return array<string, string> classe de modèle => type de matériel
     */
    public static function modelItemtypes(): array
    {
        $map = [];
        foreach (HardwareWarrantyInventory::hardwareTypes() as $itemtype) {
            $model = (new $itemtype())->getModelClass();
            if (is_string($model) && is_a($model, CommonDBTM::class, true) && !isset($map[$model])) {
                $map[$model] = $itemtype;
            }
        }

        return $map;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !self::canView()
            || !isset(self::modelItemtypes()[$item::class])
        ) {
            return '';
        }
        $dated = HardwareModelEolStore::dateFor($item::class, (int) $item->getID()) !== null ? 1 : 0;

        $label = __('Manufacturer end of life', 'grcmanager');

        return self::createTabEntry($label, $dated, $item::class, self::getIcon());
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !self::canView() || !$item->canViewItem()
            || !isset(self::modelItemtypes()[$item::class])
        ) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/hardware_model_eol_tab.html.twig',
            self::tabData($item)
        );

        return true;
    }

    /**
     * Données de l'onglet d'un modèle : sa date, le nombre de matériels de ce modèle dans les entités
     * de la personne et le seuil commun.
     *
     * @return array<string, mixed>
     */
    public static function tabData(CommonDBTM $model): array
    {
        $itemtype = self::modelItemtypes()[$model::class] ?? '';
        $assets = 0;
        if ($itemtype !== '' && $itemtype::canView()) {
            $criteria = [$model::getForeignKeyField() => (int) $model->getID()];
            $asset = new $itemtype();
            if ($asset->maybeDeleted()) {
                $criteria['is_deleted'] = 0;
            }
            if ($asset->maybeTemplate()) {
                $criteria['is_template'] = 0;
            }
            $assets = countElementsInTableForMyEntities($itemtype::getTable(), $criteria);
        }
        $date = HardwareModelEolStore::dateFor($model::class, (int) $model->getID());
        $warningDays = (int) EndOfLifeStore::settings()['warning_days'];
        $eol = WarrantyRules::endOfLife($date, date('Y-m-d'), $warningDays);

        return [
            'field'        => HardwareModelEolStore::key($model::class, (int) $model->getID()),
            'date'         => $date ?? '',
            'status'       => HardwareWarranty::endOfLifeInfos()[$eol['status']] ?? null,
            'detail'       => HardwareWarranty::endOfLifeDetail($eol),
            'assets'       => $assets,
            'asset_type'   => $itemtype !== '' ? (string) $itemtype::getTypeName($assets) : '',
            'warning_days' => $warningDays,
            'action'       => self::getSearchURL(),
            'bulk_url'     => self::getSearchURL(),
            'can_edit'     => self::canEdit(),
            'csrf_token'   => Session::getNewCSRFToken(),
        ];
    }

    /**
     * Enregistre les dates envoyées (saisie groupée ou onglet d'un modèle) et prépare le message.
     * Le droit de modifier est vérifié par l'appelant.
     *
     * @param array<mixed> $post
     */
    public static function handleSave(array $post): void
    {
        $dates = is_array($post['hardware_eol'] ?? null) ? $post['hardware_eol'] : [];
        $refused = HardwareWarranty::saveModelDates($dates);
        if ($refused > 0) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                _n(
                    '%d end of life date not saved: enter a valid date for a hardware model.',
                    '%d end of life dates not saved: enter a valid date for a hardware model.',
                    $refused,
                    'grcmanager'
                ),
                $refused
            )), false, ERROR);
        } else {
            Session::addMessageAfterRedirect(htmlescape(__('End of life dates saved.', 'grcmanager')));
        }
    }
}
