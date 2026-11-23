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
 * Issue #1055 : fournisseur de la surveillance des fuites (Have I Been Pwned, XposedOrNot).
 *
 * BreachMonitor ne connaît que ce contrat. Chaque fournisseur ramène ses réponses au même modèle :
 * une fuite est un tableau au format de Have I Been Pwned (Name, Title, Domain, BreachDate, AddedDate,
 * DataClasses, IsVerified…), lu par BreachRules::summarize(). Une adresse ou un domaine sans fuite
 * donne une réponse 200 vide ; une limite de débit, une réponse 429 avec le délai à attendre.
 */
interface BreachProvider
{
    /** Nom affiché (journaux de la tâche, messages de la configuration). */
    public function name(): string;

    /** Domaines vérifiés auprès du fournisseur (une requête pour tout le domaine), en minuscules. */
    public function subscribedDomains(): HibpResponse;

    /** Adresses d'un domaine vérifié touchées par une fuite : alias (partie avant @) => noms des fuites. */
    public function breachedDomain(string $domain): HibpResponse;

    /** Fuites d'une adresse (modèle complet de chaque fuite). */
    public function breachedAccount(string $email): HibpResponse;

    /** Toutes les fuites connues, par nom. */
    public function breaches(): HibpResponse;

    /**
     * Essai de connexion depuis la configuration, sans envoyer d'adresse réelle : 200 avec la liste des
     * domaines vérifiés, ou la réponse d'erreur (401 = clé refusée).
     */
    public function testConnection(): HibpResponse;
}
