<?php

namespace App\Support;

/**
 * Decides whether a webhook address may be called. Private, loopback and
 * link-local addresses are refused, so that a webhook cannot be used to
 * reach services on the server's own network.
 */
class WebhookTarget
{
    /** The reason the address is refused, or null when it is fine. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Use an http or https address.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Do not put a user name or password in the address.';
        }

        if (config('webhooks.allow_private_targets')) {
            return null;
        }

        if ($scheme !== 'https') {
            return 'Use an https address.';
        }

        $addresses = self::resolve(trim($host, '[]'));

        if ($addresses === []) {
            return 'The host name could not be resolved.';
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'The address points to a private or local network, which is not allowed.';
            }
        }

        return null;
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
