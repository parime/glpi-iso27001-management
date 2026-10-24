<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Network;

/**
 * Issue #144 : lecture d'une matrice des flux au format CSV générique, sans dépendance à GLPI
 * (testée unitairement). Séparateur « ; » ou « , » détecté sur l'en-tête, en-têtes français ou
 * anglais, lignes invalides rapportées avec leur numéro plutôt qu'ignorées en silence.
 *
 * Colonnes reconnues : nom, type, source, destination, protocole, ports, justification,
 * proprietaire (identifiant GLPI), date_fin (AAAA-MM-JJ ou JJ/MM/AAAA). Seules source et
 * destination sont obligatoires.
 */
final class NetworkFlowCsv
{
    private const HEADERS = [
        'name'          => ['name', 'nom', 'flux', 'libelle', 'description'],
        'flow_type'     => ['type', 'flow_type', 'type_de_flux'],
        'source_label'  => ['source', 'src', 'origine'],
        'destination_label' => ['destination', 'dst', 'dest', 'cible'],
        'protocol'      => ['protocol', 'protocole', 'proto'],
        'ports'         => ['ports', 'port', 'service'],
        'justification' => ['justification', 'motif', 'besoin', 'raison'],
        'owner'         => ['owner', 'proprietaire', 'responsable', 'login'],
        'date_end'      => ['date_end', 'date_fin', 'fin', 'expiration', 'end_date'],
    ];

    private const TYPE_ALIASES = [
        'interne' => 'internal', 'lan' => 'internal',
        'publication' => 'internet_publication', 'internet' => 'internet_publication',
        'entrant' => 'internet_publication',
        'nat' => 'internet_publication', 'reverse_proxy' => 'internet_publication',
        'sortant' => 'internet_outbound', 'outbound' => 'internet_outbound',
        'vpn' => 'site_tunnel', 'tunnel' => 'site_tunnel', 'ipsec' => 'site_tunnel', 'inter_sites' => 'site_tunnel',
        'partenaire' => 'partner_tunnel', 'partner' => 'partner_tunnel',
        'admin' => 'admin_access', 'administration' => 'admin_access', 'bastion' => 'admin_access',
        'sauvegarde' => 'backup', 'autre' => 'other',
    ];

    /**
     * @return array{rows: list<array{line: int, name: string, flow_type: string, source_label: string,
     *     destination_label: string, protocol: string, ports: string, justification: string, owner: string,
     *     date_end: ?string}>, errors: list<array{line: int, message: string}>}
     */
    public static function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $rows = [];
        $errors = [];
        $columns = null;
        $delimiter = ';';
        foreach ($lines as $index => $line) {
            $number = $index + 1;
            if (trim($line) === '') {
                continue;
            }
            if ($columns === null) {
                $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
                $columns = self::mapHeader(str_getcsv($line, $delimiter, '"', ''));
                if (!in_array('source_label', $columns, true) || !in_array('destination_label', $columns, true)) {
                    $errors[] = ['line' => $number, 'message' => 'missing_columns'];
                    break;
                }
                continue;
            }
            $values = [];
            foreach (str_getcsv($line, $delimiter, '"', '') as $position => $value) {
                if (isset($columns[$position]) && $columns[$position] !== '') {
                    $values[$columns[$position]] = trim((string) $value);
                }
            }
            $row = self::buildRow($values, $number);
            if (is_string($row)) {
                $errors[] = ['line' => $number, 'message' => $row];
            } else {
                $rows[] = $row;
            }
        }
        if ($columns === null) {
            $errors[] = ['line' => 1, 'message' => 'empty'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param list<?string> $header
     * @return array<int, string> position => clé reconnue ('' si inconnue)
     */
    private static function mapHeader(array $header): array
    {
        $columns = [];
        foreach ($header as $position => $label) {
            $label = self::slug((string) $label);
            $columns[$position] = '';
            foreach (self::HEADERS as $key => $aliases) {
                if (in_array($label, $aliases, true)) {
                    $columns[$position] = $key;
                    break;
                }
            }
        }
        return $columns;
    }

    /**
     * @param array<string, string> $values
     * @return array{line: int, name: string, flow_type: string, source_label: string,
     *     destination_label: string, protocol: string, ports: string, justification: string, owner: string,
     *     date_end: ?string}|string ligne prête, ou code d'erreur
     */
    private static function buildRow(array $values, int $number): array|string
    {
        $source = $values['source_label'] ?? '';
        $destination = $values['destination_label'] ?? '';
        if ($source === '' || $destination === '') {
            return 'missing_endpoint';
        }
        $ports = NetworkFlowRules::normalizePorts($values['ports'] ?? '');
        if ($ports === null) {
            return 'invalid_ports';
        }
        $dateEnd = self::parseDate($values['date_end'] ?? '');
        if ($dateEnd === false) {
            return 'invalid_date';
        }
        $type = self::slug($values['flow_type'] ?? '');
        $type = self::TYPE_ALIASES[$type] ?? $type;
        $protocol = NetworkFlowRules::normalizeProtocol($values['protocol'] ?? '');
        $name = $values['name'] ?? '';
        if ($name === '') {
            $name = $source . ' → ' . $destination . ($ports !== 'any' ? ' (' . $ports . ')' : '');
        }

        return [
            'line'              => $number,
            'name'              => mb_substr($name, 0, 255),
            'flow_type'         => NetworkFlowRules::normalizeType($type),
            'source_label'      => mb_substr($source, 0, 255),
            'destination_label' => mb_substr($destination, 0, 255),
            'protocol'          => $protocol,
            'ports'             => $ports,
            'justification'     => $values['justification'] ?? '',
            'owner'             => $values['owner'] ?? '',
            'date_end'          => $dateEnd,
        ];
    }

    /**
     * @return string|null|false date AAAA-MM-JJ, null si vide, false si invalide
     */
    private static function parseDate(string $value): string|null|false
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return false;
        }
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : false;
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ï' => 'i',
            'û' => 'u', 'ù' => 'u', 'ç' => 'c', 'É' => 'e',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }
}
