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

namespace GlpiPlugin\Grcmanager;

use GlpiPlugin\Grcmanager\Compatibility\Base\EbiosElementBase;
use Session;

/**
 * Issue #376 : élément d'un atelier d'une étude EBIOS Risk Manager (valeur métier, événement
 * redouté, bien support, source de risque...). Chaque élément porte son étude : droits de l'étude,
 * et une liste blanche de champs par classe (allowedFields()) pour ce que le formulaire envoie.
 */
abstract class EbiosElement extends EbiosElementBase
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    public const STUDY_FK = 'plugin_grcmanager_ebiosstudies_id';

    /**
     * Champs modifiables depuis le formulaire, hors étude.
     *
     * @return list<string>
     */
    abstract protected static function allowedFields(): array;

    /**
     * Normalise les champs propres à la classe. Renvoie false si la saisie est refusée.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    protected function normalize(array $input)
    {
        return $input;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getForStudy(int $studyId): array
    {
        return array_values((new static())->find([self::STUDY_FK => $studyId], ['id ASC']));
    }

    public static function canEditStudy(int $studyId): bool
    {
        $study = new EbiosStudy();

        return $studyId > 0 && $study->can($studyId, UPDATE);
    }

    public function prepareInputForAdd($input)
    {
        $studyId = (int) ($input[self::STUDY_FK] ?? 0);
        if (!self::canEditStudy($studyId)) {
            return false;
        }
        $clean = array_intersect_key($input, array_flip(static::allowedFields()));
        $clean[self::STUDY_FK] = $studyId;

        return $this->requireName($this->normalize($clean), true);
    }

    public function prepareInputForUpdate($input)
    {
        // L'étude d'un élément ne change jamais.
        $clean = array_intersect_key($input, array_flip(array_merge(['id'], static::allowedFields())));

        return $this->requireName($this->normalize($clean), false);
    }

    /**
     * @param array<string, mixed>|false $input
     * @return array<string, mixed>|false
     */
    private function requireName($input, bool $adding)
    {
        if ($input === false || !in_array('name', static::allowedFields(), true)) {
            return $input;
        }
        if (($adding || array_key_exists('name', $input)) && trim((string) ($input['name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(__('The name is required.', 'grcmanager'), false, ERROR);

            return false;
        }

        return $input;
    }

    public function canViewItem(): bool
    {
        $study = new EbiosStudy();

        return $study->can((int) ($this->fields[self::STUDY_FK] ?? 0), READ);
    }

    public function canCreateItem(): bool
    {
        return self::canEditStudy((int) ($this->input[self::STUDY_FK] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return self::canEditStudy((int) ($this->fields[self::STUDY_FK] ?? 0));
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }
}
