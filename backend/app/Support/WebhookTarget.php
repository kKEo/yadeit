<?php

namespace App\Support;

/**
 * Decides whether a webhook address may be called. Private, loopback,
 * link-local and other non-public addresses are refused, so that a webhook
 * cannot be used to reach services on the server's own network.
 */
class WebhookTarget
{
    /** Ranges PHP's own filter lets through although they are not the public internet. */
    private const DENIED = [
        '100.64.0.0/10',   // carrier-grade NAT, also used by cloud metadata services and overlay networks
        '192.0.0.0/24',    // protocol assignments
        '198.18.0.0/15',   // benchmarking
        '224.0.0.0/4',     // multicast
        '64:ff9b::/96',    // NAT64: an IPv4 address in disguise
        '2002::/16',       // 6to4: likewise
        'ff00::/8',        // multicast
    ];

    /** The reason the address is refused, or null when it is fine. */
    public static function problem(string $url): ?string
    {
        return self::check($url)[0];
    }

    /**
     * The reason the address is refused (or null), and the addresses that were
     * checked. The sender connects to exactly those, so a host name cannot
     * answer with a public address for the check and a private one for the call.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['Use an http or https address.', []];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['Do not put a user name or password in the address.', []];
        }

        if (config('webhooks.allow_private_targets')) {
            return [null, []];
        }

        if ($scheme !== 'https') {
            return ['Use an https address.', []];
        }

        $addresses = self::resolve(trim($host, '[]'));

        if ($addresses === []) {
            return ['The host name could not be resolved.', []];
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                return ['The address points to a private or local network, which is not allowed.', []];
            }
        }

        return [null, $addresses];
    }

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::DENIED as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $ip = inet_pton($address);
        $net = inet_pton($network);

        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
