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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Force l'anglais (en_GB) pendant chaque test de la classe, puis rétablit la langue de la session
 * (issue #1078). Pour les tests qui vérifient un libellé d'interface : ils donnent le même résultat
 * en CI (anglais) et sur l'instance de développement (français), y compris les notifications envoyées
 * dans la langue par défaut de GLPI. Chaque méthode concernée est
 * déclarée « translated-assertion » dans .glpi-security-exceptions.d/1078-assertions-traduites.
 */
trait ForcesEnglishLanguage
{
    private string $languageBeforeTest = '';
    private string $configLanguageBeforeTest = '';

    #[Before]
    protected function forceEnglishLanguage(): void
    {
        $this->languageBeforeTest = (string) ($_SESSION['glpilanguage'] ?? '');
        $this->configLanguageBeforeTest = (string) ($GLOBALS['CFG_GLPI']['language'] ?? '');
        // Langue par défaut des destinataires sans langue propre : les notifications aussi.
        $GLOBALS['CFG_GLPI']['language'] = 'en_GB';
        $_SESSION['glpilanguage'] = 'en_GB';
        \Session::loadLanguage('en_GB');
    }

    #[After]
    protected function restoreLanguage(): void
    {
        $_SESSION['glpilanguage'] = $this->languageBeforeTest;
        $GLOBALS['CFG_GLPI']['language'] = $this->configLanguageBeforeTest;
        \Session::loadLanguage($this->languageBeforeTest);
    }
}
