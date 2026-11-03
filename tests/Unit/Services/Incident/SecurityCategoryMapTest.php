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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\SecurityCategoryMap;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentRules;
use PHPUnit\Framework\TestCase;

final class SecurityCategoryMapTest extends TestCase
{
    public function testEveryClassificationHasACategory(): void
    {
        foreach (SecurityIncidentRules::ALLOWED_CATEGORIES as $classification) {
            $this->assertStringStartsWith('security', SecurityCategoryMap::categoryKey($classification));
        }
        $this->assertSame('security_malware', SecurityCategoryMap::categoryKey('malware'));
        $this->assertSame('security', SecurityCategoryMap::categoryKey('availability'), 'Branche, pas une panne.');
        $this->assertSame('security', SecurityCategoryMap::categoryKey('inconnue'));
    }

    public function testOnlyUnambiguousCategoriesGiveAClassification(): void
    {
        $this->assertSame('malware', SecurityCategoryMap::classification('security_malware'));
        $this->assertSame('malware', SecurityCategoryMap::classification('security_endpoint'));
        $this->assertNull(SecurityCategoryMap::classification('security_account'), 'Accès ou fuite : ambigu.');
        $this->assertNull(SecurityCategoryMap::classification('security_phishing'));
        $this->assertNull(SecurityCategoryMap::classification('security'));
    }
}
