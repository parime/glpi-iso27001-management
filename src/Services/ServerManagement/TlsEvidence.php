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

namespace GlpiPlugin\Grcmanager\Services\ServerManagement;

/**
 * Issue #843, lot 4 : indices réunis par RedfishTlsReader sur les protocoles TLS d'un contrôleur.
 *
 * Un seul indice d'un protocole ancien accepté suffit (1). TLS 1.2 et plus seulement (0) demande
 * un réglage explicite (version minimale, liste des versions, mode de sécurité strict) ou TLS 1.0
 * et TLS 1.1 tous deux désactivés. Sinon : inconnu.
 */
final class TlsEvidence
{
    private bool $weak = false;

    private bool $strong = false;

    /** @var array<string, bool> protocole => activé */
    private array $toggles = [];

    private int $nodes = 0;

    /** Interrupteur d'un protocole (« ssl3 », « tls10 », « tls11 », « tls12 », « tls13 »). */
    public function toggle(string $protocol, bool $enabled): void
    {
        $this->toggles[$protocol] = ($this->toggles[$protocol] ?? false) || $enabled;
        if ($enabled && in_array($protocol, ['ssl3', 'tls10', 'tls11'], true)) {
            $this->weak = true;
        }
    }

    /** Réglage des versions acceptées : $weak = la plus basse est antérieure à TLS 1.2. */
    public function setting(bool $weak): void
    {
        if ($weak) {
            $this->weak = true;
        } else {
            $this->strong = true;
        }
    }

    /** Mode de sécurité du constructeur qui n'accepte que TLS 1.2 et plus. */
    public function strictMode(): void
    {
        $this->strong = true;
    }

    /** Une valeur de plus examinée ; false au-delà de la borne. */
    public function visit(int $max): bool
    {
        return ++$this->nodes <= $max;
    }

    /** 1 (protocole ancien accepté), 0 (TLS 1.2 et plus seulement) ou BmcRules::UNKNOWN. */
    public function result(): int
    {
        if ($this->weak) {
            return 1;
        }
        $oldDisabled = isset($this->toggles['tls10'], $this->toggles['tls11'])
            && !$this->toggles['tls10'] && !$this->toggles['tls11'];
        if ($this->strong || $oldDisabled) {
            return 0;
        }

        return BmcRules::UNKNOWN;
    }
}
