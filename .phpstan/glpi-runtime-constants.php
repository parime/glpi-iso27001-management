<?php

/**
 * Constantes de chemins que GLPI définit au démarrage du noyau (Kernel), absentes d'une simple
 * analyse statique : valeurs factices, seulement pour phpstan.glpi.neon.
 */

$constants = ['GLPI_TMP_DIR', 'GLPI_DOC_DIR', 'GLPI_VAR_DIR', 'GLPI_CACHE_DIR', 'GLPI_PLUGIN_DOC_DIR', 'GLPI_LOG_DIR'];
foreach ($constants as $constant) {
    if (!defined($constant)) {
        define($constant, sys_get_temp_dir());
    }
}
