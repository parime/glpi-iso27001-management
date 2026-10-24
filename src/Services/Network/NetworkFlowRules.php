<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Network;

/**
 * Issue #144 : règles de la matrice des flux, sans dépendance à GLPI (testées unitairement).
 * Normalisation des saisies (type, protocole, ports) et contrôles automatiques : flux sans
 * justification ou sans propriétaire, flux expiré ou proche de sa date de fin, service
 * d'administration publié sur Internet.
 *
 * Les libellés sont traduits par l'appelant (PluginGrcmanagerNetworkFlow) : ce service ne porte
 * que les clés.
 */
final class NetworkFlowRules
{
    public const TYPES = [
        'internal',
        'internet_publication',
        'internet_outbound',
        'site_tunnel',
        'partner_tunnel',
        'admin_access',
        'backup',
        'other',
    ];
    public const DEFAULT_TYPE = 'internal';

    public const PROTOCOLS = ['tcp', 'udp', 'tcp_udp', 'icmp', 'ipsec', 'any'];
    public const DEFAULT_PROTOCOL = 'tcp';

    /** Jours avant la date de fin pendant lesquels un flux est signalé « bientôt expiré ». */
    public const EXPIRY_WARNING_DAYS = 30;

    /**
     * Ports de services d'administration ou de partage qu'on ne publie pas sur Internet :
     * SSH, Telnet, SMB, RDP, VNC, IPMI, WinRM, bases de données courantes.
     */
    public const ADMIN_PORTS = [22, 23, 135, 139, 445, 623, 1433, 1521, 3306, 3389, 5432, 5900, 5985, 5986];

    public const CHECK_NO_JUSTIFICATION = 'no_justification';
    public const CHECK_NO_OWNER = 'no_owner';
    public const CHECK_EXPIRED = 'expired';
    public const CHECK_EXPIRING = 'expiring';
    public const CHECK_ADMIN_EXPOSED = 'admin_exposed';
    public const CHECK_ANY_PORT_INTERNET = 'any_port_internet';

    public static function normalizeType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, self::TYPES, true) ? $value : self::DEFAULT_TYPE;
    }

    public static function normalizeProtocol(mixed $value): string
    {
        $value = strtolower(str_replace(['/', ' ', '+'], '_', trim((string) $value)));
        $aliases = ['tcp_udp' => 'tcp_udp', 'udp_tcp' => 'tcp_udp', 'both' => 'tcp_udp', '*' => 'any', 'all' => 'any',
            'ip' => 'any', 'esp' => 'ipsec', 'ike' => 'ipsec'];
        $value = $aliases[$value] ?? $value;
        return in_array($value, self::PROTOCOLS, true) ? $value : self::DEFAULT_PROTOCOL;
    }

    /**
     * Ports saisis (« 443 », « 80, 443 », « 8000-8100 », « any ») ramenés à une forme canonique
     * « 80,443,8000-8100 », ou null si la saisie est invalide. Vide ou « any » : tous les ports.
     */
    public static function normalizePorts(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, ['any', '*', 'all', 'tous'], true)) {
            return 'any';
        }
        $parts = [];
        foreach (preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $part, $m) !== 1) {
                return null;
            }
            $from = (int) $m[1];
            $to = isset($m[2]) ? (int) $m[2] : $from;
            if ($from < 1 || $to > 65535 || $to < $from) {
                return null;
            }
            $parts[] = $from === $to ? (string) $from : $from . '-' . $to;
        }
        $parts = array_values(array_unique($parts));
        return $parts === [] ? 'any' : implode(',', $parts);
    }

    /**
     * Le port fait-il partie de la liste canonique (« any » couvre tout) ?
     */
    public static function portsInclude(string $ports, int $port): bool
    {
        if ($ports === 'any') {
            return true;
        }
        foreach (explode(',', $ports) as $part) {
            [$from, $to] = array_map('intval', array_pad(explode('-', $part), 2, $part));
            if ($port >= $from && $port <= $to) {
                return true;
            }
        }
        return false;
    }

    /**
     * Contrôles automatiques d'un flux actif.
     *
     * @param array{flow_type?: mixed, ports?: mixed, justification?: mixed, users_id?: mixed,
     *     date_end?: mixed, is_active?: mixed} $flow
     * @return list<string> clés CHECK_*
     */
    public static function checks(array $flow, string $today): array
    {
        if (isset($flow['is_active']) && (int) $flow['is_active'] === 0) {
            return [];
        }
        $checks = [];
        if (trim(strip_tags((string) ($flow['justification'] ?? ''))) === '') {
            $checks[] = self::CHECK_NO_JUSTIFICATION;
        }
        if ((int) ($flow['users_id'] ?? 0) <= 0) {
            $checks[] = self::CHECK_NO_OWNER;
        }
        $end = substr((string) ($flow['date_end'] ?? ''), 0, 10);
        if ($end !== '' && $end !== '0000-00-00') {
            if ($end < $today) {
                $checks[] = self::CHECK_EXPIRED;
            } elseif ($end <= date('Y-m-d', (int) strtotime($today . ' +' . self::EXPIRY_WARNING_DAYS . ' days'))) {
                $checks[] = self::CHECK_EXPIRING;
            }
        }
        if (self::normalizeType($flow['flow_type'] ?? '') === 'internet_publication') {
            $ports = self::normalizePorts($flow['ports'] ?? '') ?? 'any';
            if ($ports === 'any') {
                $checks[] = self::CHECK_ANY_PORT_INTERNET;
            } elseif (self::exposedAdminPorts($ports) !== []) {
                $checks[] = self::CHECK_ADMIN_EXPOSED;
            }
        }
        return $checks;
    }

    /**
     * @return list<int>
     */
    public static function exposedAdminPorts(string $ports): array
    {
        $exposed = static fn (int $port): bool => self::portsInclude($ports, $port);
        return array_values(array_filter(self::ADMIN_PORTS, $exposed));
    }

    /**
     * Les contrôles qui empêchent de présenter le flux comme maîtrisé (les autres sont des alertes).
     */
    public static function isBlocking(string $check): bool
    {
        return in_array($check, [self::CHECK_NO_JUSTIFICATION, self::CHECK_EXPIRED, self::CHECK_ADMIN_EXPOSED,
            self::CHECK_ANY_PORT_INTERNET], true);
    }
}
