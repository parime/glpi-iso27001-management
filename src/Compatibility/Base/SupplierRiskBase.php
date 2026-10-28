<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compatibility\Base;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Compatibility\GlpiVersion;
use GlpiPlugin\Grcmanager\SupplierRisk;

/*
 * Parent of \\GlpiPlugin\Grcmanager\SupplierRisk.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class SupplierRiskBase extends CommonDBTM
    {
        public static string $rightname = \GlpiPlugin\Grcmanager\SupplierRisk::RIGHTNAME;
        // Registre sensible : qui a modifié quoi, et quand (onglet Historique).
        public bool $dohistory = true;
    }
} else {
    abstract class SupplierRiskBase extends CommonDBTM
    {
        public static $rightname = \GlpiPlugin\Grcmanager\SupplierRisk::RIGHTNAME;
        // Registre sensible : qui a modifié quoi, et quand (onglet Historique).
        public $dohistory = true;
    }
}
