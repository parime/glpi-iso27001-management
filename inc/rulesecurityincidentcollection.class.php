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

/**
 * Required, not optional — same class of surprise as `PluginGrcmanagerSecurityIncidentCost`/
 * `PluginGrcmanagerSecurityIncidentTemplate` (see their own docblocks):
 * `CommonItilObject_Item::prepareInputForAdd()` unconditionally calls
 * `CommonITILObject::getRuleCollectionClassInstance()`, which builds the expected class name as
 * `'Rule' . static::getType() . 'Collection'` and throws a `RuntimeException` if it doesn't exist
 * — confirmed live, the very first attempt to link an asset to an incident (the "Item" tab) fataled
 * with exactly that error. Minimal shape, mirroring GLPI core's own `RuleChangeCollection` — no
 * predefined business rules are seeded, this only satisfies the convention so linking an asset
 * doesn't crash; an administrator can still define real rules from **Configuration > Rules**
 * (`Plugin::registerClass()` in setup.php makes this itemtype discoverable there) if that
 * capability turns out to be wanted, without any further code change.
 */
class RulePluginGrcmanagerSecurityIncidentCollection extends RuleCommonITILObjectCollection
{
    public static $rightname = 'rule_grcmanager_securityincident';

    public $menu_option = 'securityincident';

    public function getTitle()
    {
        return __('Business rules for security incidents', 'grcmanager');
    }
}
