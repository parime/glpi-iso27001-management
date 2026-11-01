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

namespace GlpiPlugin\Grcmanager\Services\Breach;

/**
 * Réponse de l'API Have I Been Pwned : code HTTP, données décodées, délai demandé en cas de 429.
 */
final class HibpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $data = null,
        public readonly int $retryAfter = 0,
        public readonly string $error = '',
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === 200;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /** Clé absente ou invalide : inutile d'insister. */
    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }
}
