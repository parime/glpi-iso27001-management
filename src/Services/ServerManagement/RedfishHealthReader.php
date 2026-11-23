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
 * Issue #843, lot 3 : santé détaillée d'un serveur lue par Redfish, en LECTURE SEULE, par le même
 * client (GET uniquement, adresse du contrôleur seulement, chemins sous /redfish/v1, requêtes bornées).
 *
 * - Châssis : ventilateurs, températures, alimentations. Ressources ThermalSubsystem (Fans,
 *   ThermalMetrics.TemperatureReadingsCelsius) et PowerSubsystem (PowerSupplies) d'abord ; Thermal
 *   et Power, dépréciées depuis Chassis v1_15_0 au profit des précédentes, seulement à défaut
 *   (https://redfish.dmtf.org/schemas/v1/Chassis.v1_25_0.json, ThermalSubsystem.v1_3_3.json,
 *   Fan.v1_5_2.json, ThermalMetrics.v1_3_2.json, PowerSubsystem.v1_1_3.json, PowerSupply.v1_6_0.json) ;
 * - Stockage du système : disques ;
 * - Journaux du contrôleur (LogServices) : événements récents (MAX_EVENTS, les plus récents d'abord).
 *
 * Chaque rubrique est facultative : une rubrique illisible est seulement absente. read = false si
 * aucune n'a pu être lue (l'appelant garde alors la lecture précédente). Les emplacements vides
 * (State « Absent ») ne sont pas repris.
 *
 * @phpstan-type Component array{kind: string, name: string, health: string, reading: string}
 * @phpstan-type Event array{date: string|null, severity: string, message: string, code: string}
 */
final class RedfishHealthReader
{
    public const KIND_POWER = 'power';
    public const KIND_DRIVE = 'drive';
    public const KIND_FAN = 'fan';
    public const KIND_TEMPERATURE = 'temperature';

    public const MAX_CHASSIS = 2;
    public const MAX_FANS = 12;
    public const MAX_SUPPLIES = 8;
    public const MAX_STORAGE = 4;
    public const MAX_DRIVES = 24;
    public const MAX_COMPONENTS = 120;
    public const MAX_LOG_SERVICES = 3;
    public const MAX_EVENTS = 20;

    public function __construct(private readonly RedfishClient $client)
    {
    }

    /**
     * @return array{read: bool, components: list<Component>, events: list<Event>}
     */
    public function read(): array
    {
        $out = ['read' => false, 'components' => [], 'events' => []];
        $root = $this->data('/redfish/v1/');
        if ($root === null) {
            return $out;
        }
        $system = null;
        $systems = $this->members(self::link($root, 'Systems') ?? '/redfish/v1/Systems', 1);
        if ($systems !== null && $systems !== []) {
            $system = $this->data($systems[0]);
        }
        $read = false;
        $components = [];

        // Châssis : ceux liés au système, sinon la collection de la racine.
        $chassis = [];
        foreach (is_array($system['Links']['Chassis'] ?? null) ? $system['Links']['Chassis'] : [] as $link) {
            $path = BmcRules::safePath(is_array($link) ? ($link['@odata.id'] ?? null) : null);
            if ($path !== null && count($chassis) < self::MAX_CHASSIS) {
                $chassis[] = $path;
            }
        }
        if ($chassis === []) {
            $chassis = $this->members(
                self::link($root, 'Chassis') ?? '/redfish/v1/Chassis',
                self::MAX_CHASSIS
            ) ?? [];
        }
        foreach (array_unique($chassis) as $path) {
            $resource = $this->data($path);
            if ($resource === null) {
                continue;
            }
            // Ressources récentes (Chassis v1_15 et plus) d'abord ; Thermal et Power, dépréciées, à défaut.
            $subsystem = $this->thermalSubsystem($resource);
            if ($subsystem !== null) {
                $read = true;
                array_push($components, ...$subsystem);
            } else {
                $thermal = $this->data(self::link($resource, 'Thermal'));
                if ($thermal !== null) {
                    $read = true;
                    foreach (self::list($thermal, 'Fans') as $fan) {
                        $components[] = self::component(self::KIND_FAN, $fan, self::fanReading($fan));
                    }
                    foreach (self::list($thermal, 'Temperatures') as $sensor) {
                        $components[] = self::component(
                            self::KIND_TEMPERATURE,
                            $sensor,
                            self::temperatureReading($sensor)
                        );
                    }
                }
            }
            $supplies = $this->powerSubsystem($resource);
            if ($supplies !== null) {
                $read = true;
                array_push($components, ...$supplies);
            } else {
                $power = $this->data(self::link($resource, 'Power'));
                if ($power !== null) {
                    $read = true;
                    foreach (self::list($power, 'PowerSupplies') as $supply) {
                        $components[] = self::component(self::KIND_POWER, $supply, self::powerReading($supply));
                    }
                }
            }
        }

        // Disques du stockage du système.
        $storage = is_array($system) ? $this->members(self::link($system, 'Storage'), self::MAX_STORAGE) : null;
        $drives = 0;
        foreach ($storage ?? [] as $path) {
            $resource = $this->data($path);
            if ($resource === null) {
                continue;
            }
            $read = true;
            foreach (is_array($resource['Drives'] ?? null) ? $resource['Drives'] : [] as $link) {
                if ($drives >= self::MAX_DRIVES) {
                    break 2;
                }
                $drive = $this->data(is_array($link) ? ($link['@odata.id'] ?? null) : null);
                if ($drive === null) {
                    continue;
                }
                ++$drives;
                $components[] = self::component(self::KIND_DRIVE, $drive, self::driveReading($drive));
            }
        }

        $events = $this->events($root);
        if ($events !== null) {
            $read = true;
        }

        $components = array_values(array_filter($components));

        return [
            'read'       => $read,
            'components' => array_slice($components, 0, self::MAX_COMPONENTS),
            'events'     => $events ?? [],
        ];
    }

    /**
     * Événements récents du premier journal du contrôleur qui en a (IML, SEL, Lifecycle...).
     *
     * @param array<mixed> $root
     * @return list<Event>|null null si aucun journal n'est lisible
     */
    private function events(array $root): ?array
    {
        $managers = $this->members(self::link($root, 'Managers') ?? '/redfish/v1/Managers', 1);
        $manager = $managers !== null && $managers !== [] ? $this->data($managers[0]) : null;
        if ($manager === null) {
            return null;
        }
        $services = $this->members(self::link($manager, 'LogServices'), self::MAX_LOG_SERVICES);
        foreach ($services ?? [] as $path) {
            $service = $this->data($path);
            $entries = $service === null ? null : $this->data(self::link($service, 'Entries'));
            if ($entries === null) {
                continue;
            }
            $events = [];
            foreach (self::list($entries, 'Members') as $entry) {
                $events[] = [
                    'date'     => self::dateTimeOf($entry['Created'] ?? null),
                    'severity' => BmcRules::normalizeHealth($entry['Severity'] ?? null),
                    'message'  => mb_substr(trim((string) ($entry['Message'] ?? '')), 0, 500),
                    'code'     => mb_substr(trim((string) ($entry['MessageId'] ?? '')), 0, 128),
                ];
            }
            $events = array_values(array_filter($events, static fn (array $e): bool => $e['message'] !== ''));
            usort($events, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

            return array_slice($events, 0, self::MAX_EVENTS);
        }

        return null;
    }

    /**
     * ThermalSubsystem : ventilateurs (collection Fans, une ressource Fan par membre, vitesse dans
     * SpeedPercent) et températures (ThermalMetrics.TemperatureReadingsCelsius, sans état de santé
     * ni seuil dans cet extrait). null si le châssis n'a pas cette ressource ou si elle est illisible.
     *
     * @param array<mixed> $chassis
     * @return list<Component|null>|null
     */
    private function thermalSubsystem(array $chassis): ?array
    {
        $subsystem = $this->data(self::link($chassis, 'ThermalSubsystem'));
        if ($subsystem === null) {
            return null;
        }
        $components = [];
        foreach ($this->members(self::link($subsystem, 'Fans'), self::MAX_FANS) ?? [] as $path) {
            $fan = $this->data($path);
            if ($fan !== null) {
                $speed = is_array($fan['SpeedPercent'] ?? null) ? $fan['SpeedPercent'] : [];
                $components[] = self::component(self::KIND_FAN, $fan, self::fanSpeedReading($speed));
            }
        }
        $metrics = $this->data(self::link($subsystem, 'ThermalMetrics'));
        foreach ($metrics === null ? [] : self::list($metrics, 'TemperatureReadingsCelsius') as $sensor) {
            $name = trim((string) ($sensor['DeviceName'] ?? ''));
            if ($name === '') {
                $source = (string) ($sensor['DataSourceUri'] ?? '');
                $name = $source !== '' ? basename($source) : (string) ($sensor['PhysicalContext'] ?? '');
            }
            $reading = is_numeric($sensor['Reading'] ?? null) ? round((float) $sensor['Reading']) . ' °C' : '';
            $components[] = self::component(self::KIND_TEMPERATURE, ['Name' => $name], $reading);
        }

        return $components;
    }

    /**
     * PowerSubsystem : alimentations (collection PowerSupplies, une ressource PowerSupply par membre).
     *
     * @param array<mixed> $chassis
     * @return list<Component|null>|null
     */
    private function powerSubsystem(array $chassis): ?array
    {
        $subsystem = $this->data(self::link($chassis, 'PowerSubsystem'));
        if ($subsystem === null) {
            return null;
        }
        $components = [];
        foreach ($this->members(self::link($subsystem, 'PowerSupplies'), self::MAX_SUPPLIES) ?? [] as $path) {
            $supply = $this->data($path);
            if ($supply !== null) {
                $components[] = self::component(self::KIND_POWER, $supply, self::powerReading($supply));
            }
        }

        return $components;
    }

    /** Vitesse d'un ventilateur (Fan.SpeedPercent : Reading en %, SpeedRPM facultatif). */
    private static function fanSpeedReading(array $speed): string
    {
        if (is_numeric($speed['Reading'] ?? null)) {
            return rtrim(rtrim(sprintf('%.1f', (float) $speed['Reading']), '0'), '.') . ' %';
        }
        if (is_numeric($speed['SpeedRPM'] ?? null)) {
            return round((float) $speed['SpeedRPM']) . ' RPM';
        }

        return '';
    }

    /** @return array<mixed>|null */
    private function data(mixed $path): ?array
    {
        if ($path === null) {
            return null;
        }
        $answer = $this->client->get($path);

        return $answer['error'] === '' && is_array($answer['data']) ? $answer['data'] : null;
    }

    /** @return list<string>|null */
    private function members(mixed $path, int $max): ?array
    {
        $data = $this->data($path);
        if ($data === null) {
            return null;
        }
        $members = [];
        foreach (self::list($data, 'Members') as $member) {
            $id = BmcRules::safePath($member['@odata.id'] ?? null);
            if ($id !== null) {
                $members[] = $id;
            }
            if (count($members) >= $max) {
                break;
            }
        }

        return $members;
    }

    /** @return list<array<mixed>> */
    private static function list(array $resource, string $property): array
    {
        $items = is_array($resource[$property] ?? null) ? $resource[$property] : [];

        return array_values(array_filter($items, 'is_array'));
    }

    private static function link(array $resource, string $property): ?string
    {
        $value = $resource[$property] ?? null;

        return BmcRules::safePath(is_array($value) ? ($value['@odata.id'] ?? null) : null);
    }

    /**
     * @param array<mixed> $resource
     * @return Component|null null pour un emplacement vide
     */
    private static function component(string $kind, array $resource, string $reading): ?array
    {
        $status = is_array($resource['Status'] ?? null) ? $resource['Status'] : [];
        if (strcasecmp((string) ($status['State'] ?? ''), 'Absent') === 0) {
            return null;
        }
        $name = trim((string) ($resource['Name'] ?? ($resource['MemberId'] ?? ($resource['Id'] ?? ''))));
        $health = BmcRules::normalizeHealth($status['Health'] ?? null);
        if ($kind === self::KIND_DRIVE && ($resource['FailurePredicted'] ?? false) === true) {
            $health = BmcRules::worstHealth($health, BmcRules::HEALTH_WARNING);
        }

        return [
            'kind'    => $kind,
            'name'    => mb_substr($name !== '' ? $name : $kind, 0, 255),
            'health'  => $health,
            'reading' => mb_substr($reading, 0, 255),
        ];
    }

    /** @param array<mixed> $fan */
    private static function fanReading(array $fan): string
    {
        if (!is_numeric($fan['Reading'] ?? null)) {
            return '';
        }
        $unit = (string) ($fan['ReadingUnits'] ?? 'RPM');

        $value = rtrim(rtrim(sprintf('%.1f', (float) $fan['Reading']), '0'), '.');

        return $value . ' ' . ($unit === 'Percent' ? '%' : 'RPM');
    }

    /** @param array<mixed> $sensor */
    private static function temperatureReading(array $sensor): string
    {
        if (!is_numeric($sensor['ReadingCelsius'] ?? null)) {
            return '';
        }
        $reading = round((float) $sensor['ReadingCelsius']) . ' °C';
        $limit = $sensor['UpperThresholdCritical'] ?? ($sensor['UpperThresholdFatal'] ?? null);

        return is_numeric($limit) && (float) $limit > 0 ? $reading . ' / ' . round((float) $limit) . ' °C' : $reading;
    }

    /** @param array<mixed> $supply */
    private static function powerReading(array $supply): string
    {
        $parts = [];
        if (is_string($supply['Model'] ?? null) && trim($supply['Model']) !== '') {
            $parts[] = trim($supply['Model']);
        }
        if (is_numeric($supply['PowerCapacityWatts'] ?? null) && (float) $supply['PowerCapacityWatts'] > 0) {
            $parts[] = round((float) $supply['PowerCapacityWatts']) . ' W';
        }

        return implode(' · ', $parts);
    }

    /** @param array<mixed> $drive */
    private static function driveReading(array $drive): string
    {
        $parts = [];
        if (is_string($drive['Model'] ?? null) && trim($drive['Model']) !== '') {
            $parts[] = trim($drive['Model']);
        }
        if (is_numeric($drive['CapacityBytes'] ?? null) && (float) $drive['CapacityBytes'] > 0) {
            $bytes = (float) $drive['CapacityBytes'];
            $parts[] = $bytes >= 1e12 ? round($bytes / 1e12, 1) . ' TB' : round($bytes / 1e9) . ' GB';
        }
        if (is_string($drive['MediaType'] ?? null) && trim($drive['MediaType']) !== '') {
            $parts[] = trim($drive['MediaType']);
        }
        if (is_numeric($drive['PredictedMediaLifeLeftPercent'] ?? null)) {
            $parts[] = round((float) $drive['PredictedMediaLifeLeftPercent']) . ' %';
        }

        return implode(' · ', $parts);
    }

    /** Date et heure Redfish (ISO 8601) vers « Y-m-d H:i:s » en UTC, null si illisible. */
    public static function dateTimeOf(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }
}
