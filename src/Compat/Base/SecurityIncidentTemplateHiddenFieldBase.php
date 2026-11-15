<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat\Base;

use GlpiPlugin\Grcmanager\Compat\GlpiVersion;
use ITILTemplateHiddenField;
use PluginGrcmanagerSecurityIncidentTemplateHiddenField;

/*
 * Parent of \PluginGrcmanagerSecurityIncidentTemplateHiddenField.
 *
 * Redeclares the GLPI core properties that class overrides: untyped on GLPI 11, typed on GLPI 12
 * (see GlpiVersion). A class, not a trait: on PHP 8.2-8.4 a trait cannot redeclare an inherited
 * property with another value (fatal error, or on PHP 8.2 a slot silently shared with the
 * parent). Values come from the child class's own constants.
 */
if (GlpiVersion::isAtLeast12()) {
    abstract class SecurityIncidentTemplateHiddenFieldBase extends ITILTemplateHiddenField
    {
        public static string $itemtype = PluginGrcmanagerSecurityIncidentTemplateHiddenField::ITEMTYPE;

        public static string $items_id = PluginGrcmanagerSecurityIncidentTemplateHiddenField::ITEMS_ID;
    }
} else {
    abstract class SecurityIncidentTemplateHiddenFieldBase extends ITILTemplateHiddenField
    {
        public static $itemtype = PluginGrcmanagerSecurityIncidentTemplateHiddenField::ITEMTYPE;

        public static $items_id = PluginGrcmanagerSecurityIncidentTemplateHiddenField::ITEMS_ID;
    }
}
