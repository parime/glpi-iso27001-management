<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Risk;

/**
 * Fixed default set of GLPI CMDB itemtypes a risk can be linked to (issue #25, "Lien registre de
 * risques <-> actifs GLPI (CMDB)"), see \GlpiPlugin\Grcmanager\Risk::getLinkableItemtypes(). Mirrors the
 * sibling plugin assetsign-glpi's own PLUGIN_ASSETSIGN_DEFAULT_ITEMTYPES convention
 * (src/GlpiPlugin/Assetsign/Config.php) for consistency between the two plugins of this same
 * author: a plain constant list of GLPI's own managed asset itemtypes.
 *
 * Kept here as pure PHP (no GLPI dependency) so it can be reused both by setup.php (registers the
 * reverse "Risques" tab on each of these itemtypes via Plugin::registerClass()/addtabon, executed
 * too early in GLPI's plugin boot sequence to also enumerate active custom asset definitions, same
 * limitation already documented by assetsign-glpi for its own addtabon registration) and by unit
 * tests, without either needing a running GLPI instance.
 */
final class LinkableItemtypes
{
    public const DEFAULT_ITEMTYPES = [
        'Computer',
        'Monitor',
        'NetworkEquipment',
        'Peripheral',
        'Phone',
        'Printer',
        'Software',
    ];
}
