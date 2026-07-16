<?php

namespace Sendtrap\Core\Support;

/**
 * Matches an IP address against a list of allow rules (single IPs or CIDR
 * ranges, IPv4 and IPv6). An empty rule list means "no restriction".
 */
class IpAllowList
{
    /**
     * @param  array<int, string>|null  $rules
     */
    public static function allows(?array $rules, ?string $ip): bool
    {
        $rules = array_values(array_filter(array_map('trim', $rules ?? [])));

        if (empty($rules)) {
            return true; // no restriction configured
        }

        if (! $ip) {
            return false;
        }

        foreach ($rules as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trim, drop blanks, and de-dupe a list of rules.
     *
     * @param  array<int, string>|null  $rules
     * @return array<int, string>
     */
    public static function normalize(?array $rules): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $rules ?? []))));
    }

    public static function matches(string $ip, string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return inet_pton($ip) !== false
                && inet_pton($rule) !== false
                && inet_pton($ip) === inet_pton($rule);
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // mismatched families or invalid input
        }

        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainder) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }

    /**
     * Reserved/private ranges an outbound webhook must never be allowed to
     * target — loopback, link-local (incl. the cloud metadata endpoint),
     * RFC1918 private ranges, and their IPv6 equivalents.
     */
    protected const RESERVED_RANGES = [
        '127.0.0.0/8', '0.0.0.0/8', '169.254.0.0/16',
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    /**
     * Whether an IP falls inside a reserved/private range — used to block
     * SSRF via outbound webhook deliveries.
     */
    public static function isReservedOrPrivate(string $ip): bool
    {
        foreach (self::RESERVED_RANGES as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a single allow rule (IP or CIDR).
     */
    public static function validRule(string $rule): bool
    {
        $rule = trim($rule);

        if ($rule === '') {
            return false;
        }

        if (! str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        if (! ctype_digit($bits)) {
            return false;
        }

        $ip = filter_var($subnet, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }

        $max = str_contains($subnet, ':') ? 128 : 32;

        return (int) $bits >= 0 && (int) $bits <= $max;
    }
}
