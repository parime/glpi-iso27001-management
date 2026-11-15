<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Compat;

/**
 * GLPI 11 and GLPI 12 from a single code base.
 *
 * GLPI 12 typed most properties of its base classes (CommonGLPI::$rightname becomes `string`,
 * CommonDBRelation::$itemtype_1 becomes `?string`...), while GLPI 11 leaves them untyped. PHP
 * requires a subclass to repeat EXACTLY the parent's type, so no single declaration works on both
 * versions (fatal error either way). Each affected class therefore extends an intermediate class
 * (Compat/Base/) declared according to the installed version: typed on 12, untyped on 11;
 * values come from the child class's own constants. A class rather than a trait: on PHP 8.2-8.4 a
 * trait cannot redeclare an inherited property with another value.
 */
final class GlpiVersion
{
    public static function isAtLeast12(): bool
    {
        // '12.0.0-dev' (not '12.0.0'): includes GLPI 12 pre-releases (alpha/beta/rc), which
        // already carry the API changes.
        return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '12.0.0-dev', '>=');
    }
}
