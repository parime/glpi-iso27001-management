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

namespace GlpiPlugin\Grcmanager\Services\Network;

/**
 * Issue #144, phase 2 : règles de filtrage d'une sauvegarde pfSense (config.xml, Diagnostics >
 * Backup & Restore) converties en lignes d'import de la matrice des flux, même forme que
 * NetworkFlowCsv::parse(). Seules les règles actives qui autorisent un flux (« pass ») sont
 * reprises ; les alias d'hôtes, de réseaux et de ports sont résolus. Aucune justification ni
 * aucun propriétaire n'est déduit : ils restent à renseigner dans la matrice.
 */
final class NetworkFlowPfsense
{
    /**
     * @return array{rows: list<array{line: int, name: string, flow_type: string, source_label: string,
     *     destination_label: string, protocol: string, ports: string, justification: string, owner: string,
     *     date_end: ?string}>, errors: list<array{line: int, message: string}>}
     */
    public static function parse(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false || $xml->getName() !== 'pfsense') {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'not_pfsense']]];
        }

        $aliases = [];
        foreach ($xml->aliases->alias ?? [] as $alias) {
            $aliases[(string) $alias->name] = [
                'type'    => (string) $alias->type,
                'address' => trim((string) $alias->address),
            ];
        }

        $rows = [];
        $errors = [];
        $number = 0;
        foreach ($xml->filter->rule ?? [] as $rule) {
            $number++;
            if ((string) $rule->type !== 'pass' || isset($rule->disabled)) {
                continue;
            }
            $interface = strtolower((string) $rule->interface);
            $source = self::endpoint($rule->source, $aliases);
            $destination = self::endpoint($rule->destination, $aliases);
            $portsInput = (string) ($rule->destination->port ?? '');
            if (isset($aliases[$portsInput]) && $aliases[$portsInput]['type'] === 'port') {
                $portsInput = $aliases[$portsInput]['address'];
            }
            $ports = NetworkFlowRules::normalizePorts(str_replace(':', '-', $portsInput));
            if ($ports === null) {
                $errors[] = ['line' => $number, 'message' => 'invalid_ports'];
                continue;
            }
            $protocol = NetworkFlowRules::normalizeProtocol((string) ($rule->protocol ?? '') ?: 'any');
            $description = trim((string) $rule->descr);
            $rows[] = [
                'line'              => $number,
                'name'              => $description !== ''
                    ? $description
                    : sprintf('pfSense %s : %s → %s', strtoupper($interface), $source, $destination),
                'flow_type'         => self::flowType($interface, $source, $destination),
                'source_label'      => $source,
                'destination_label' => $destination,
                'protocol'          => $protocol,
                'ports'             => $ports,
                'justification'     => '',
                'owner'             => '',
                'date_end'          => null,
            ];
        }
        if ($number === 0) {
            $errors[] = ['line' => 1, 'message' => 'no_rule'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * « any », réseau d'une interface (« lan » → « Réseau LAN »), adresse ou alias résolu, avec
     * « non » devant une négation.
     *
     * @param array<string, array{type: string, address: string}> $aliases
     */
    private static function endpoint(?\SimpleXMLElement $side, array $aliases): string
    {
        if ($side === null || isset($side->any)) {
            $label = 'any';
        } elseif (isset($side->network)) {
            $network = strtolower((string) $side->network);
            if (preg_match('/^(wan|lan|opt\d+)ip$/', $network, $m) === 1) {
                $label = 'IP ' . strtoupper($m[1]);
            } elseif (preg_match('/^(wan|lan|opt\d+)$/', $network) === 1) {
                $label = 'Réseau ' . strtoupper($network);
            } else {
                $label = $network;
            }
        } else {
            $address = (string) ($side->address ?? '');
            $label = isset($aliases[$address]) && $aliases[$address]['address'] !== ''
                ? $address . ' (' . str_replace(' ', ', ', $aliases[$address]['address']) . ')'
                : $address;
        }
        if ($side !== null && isset($side->not)) {
            $label = 'non ' . $label;
        }

        return $label;
    }

    /**
     * Règle sur l'interface WAN depuis n'importe où : publication sur Internet ; depuis le LAN
     * vers n'importe où : accès à Internet ; sinon flux interne.
     */
    private static function flowType(string $interface, string $source, string $destination): string
    {
        if ($interface === 'wan' && $source === 'any') {
            return 'internet_publication';
        }
        if ($interface !== 'wan' && $destination === 'any') {
            return 'internet_outbound';
        }

        return 'internal';
    }
}
